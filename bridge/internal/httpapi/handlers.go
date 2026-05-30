package httpapi

import (
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"

	"log/slog"
	"net/http"
	"net/url"

	"draftsync-bridge/internal/config"
	"draftsync-bridge/internal/oauth"
	"draftsync-bridge/internal/store"
)

// Handlers holds dependencies for HTTP endpoint handlers.
type Handlers struct {
	Store  *store.S
	Google *oauth.Google
	Config *config.C
	Log    *slog.Logger
}

// writeJSON writes a JSON body with the given status.
func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

// writeError writes a standardised JSON error response.
func writeError(w http.ResponseWriter, status int, code, msg string) {
	writeJSON(w, status, map[string]string{"error": code, "message": msg})
}

// generateState produces a cryptographically random 32-byte base64url string.
func generateState() (string, error) {
	b := make([]byte, 32)
	if _, err := rand.Read(b); err != nil {
		return "", err
	}
	return base64.RawURLEncoding.EncodeToString(b), nil
}

// Healthz returns 200 with bridge health information.
func (h *Handlers) Healthz(w http.ResponseWriter, r *http.Request) {
	if err := h.Store.Ping(r.Context()); err != nil {
		writeJSON(w, http.StatusServiceUnavailable, map[string]string{"status": "degraded", "db": err.Error()})
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
}

// Auth initiates the Google OAuth flow.
// GET /api/auth?redirect_uri=<...>&state=<...>
func (h *Handlers) Auth(w http.ResponseWriter, r *http.Request) {
	pluginRedirectURI := r.URL.Query().Get("redirect_uri")
	pluginState := r.URL.Query().Get("state")
	if pluginRedirectURI == "" || pluginState == "" {
		writeError(w, http.StatusBadRequest, "missing_param", "redirect_uri and state are required")
		return
	}

	if err := h.Config.IsAllowedRedirect(pluginRedirectURI); err != nil {
		writeError(w, http.StatusBadRequest, "not_allowed", err.Error())
		return
	}

	bridgeState, err := generateState()
	if err != nil {
		h.Log.Error("generate state", "err", err)
		writeError(w, http.StatusInternalServerError, "server_error", "failed to generate state")
		return
	}

	if err := h.Store.PutState(r.Context(), store.AuthState{
		BridgeState: bridgeState,
		PluginState: pluginState,
		RedirectURI: pluginRedirectURI,
	}); err != nil {
		h.Log.Error("put state", "err", err)
		writeError(w, http.StatusInternalServerError, "server_error", "failed to persist auth state")
		return
	}

	googleURL := h.Google.AuthorizeURL(bridgeState)
	http.Redirect(w, r, googleURL, http.StatusFound)
}

// Callback handles the Google OAuth callback.
// GET /api/callback?code=<...>&state=<bridge_state>
//
// The bridge exchanges the Google authorization code server-side, stores the resulting
// tokens under a short-lived broker code, and redirects the plugin with that broker code.
func (h *Handlers) Callback(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()

	if q.Get("error") != "" {
		writeError(w, http.StatusBadRequest, "google_error", q.Get("error"))
		return
	}

	googleCode := q.Get("code")
	bridgeState := q.Get("state")
	if googleCode == "" || bridgeState == "" {
		writeError(w, http.StatusBadRequest, "missing_param", "code and state are required")
		return
	}

	st, err := h.Store.GetState(r.Context(), bridgeState, h.Config.StateTTL)
	if err != nil {
		if errors.Is(err, store.ErrStateNotFound) {
			writeError(w, http.StatusForbidden, "invalid_state", "state not found, expired, or already used")
			return
		}
		h.Log.Error("get state", "err", err)
		writeError(w, http.StatusInternalServerError, "server_error", "failed to retrieve state")
		return
	}

	// Exchange the Google authorization code server-side using the bridge's callback URL.
	token, err := h.Google.ExchangeCode(r.Context(), googleCode)
	if err != nil {
		h.Log.Error("callback exchange code", "err", err)
		writeError(w, http.StatusBadGateway, "exchange_failed", "failed to exchange Google authorization code")
		return
	}

	if token.AccessToken == "" || token.RefreshToken == "" {
		h.Log.Error("callback incomplete token", "access_empty", token.AccessToken == "", "refresh_empty", token.RefreshToken == "")
		writeError(w, http.StatusBadGateway, "incomplete_token", "Google returned incomplete tokens")
		return
	}

	// Generate a broker code and store the token bundle for plugin redemption.
	brokerCode, err := generateState()
	if err != nil {
		h.Log.Error("generate broker code", "err", err)
		writeError(w, http.StatusInternalServerError, "server_error", "failed to generate broker code")
		return
	}

	if err := h.Store.PutPendingToken(r.Context(), store.PendingToken{
		BrokerCode:   brokerCode,
		AccessToken:  token.AccessToken,
		RefreshToken: token.RefreshToken,
		ExpiresIn:    token.ExpiresIn,
	}); err != nil {
		h.Log.Error("put pending token", "err", err)
		writeError(w, http.StatusInternalServerError, "server_error", "failed to store pending token")
		return
	}

	// Consume the auth state only after token persistence succeeded.
	// If DeleteState fails (unexpected), the state is still usable on retry.
	if err := h.Store.DeleteState(r.Context(), bridgeState); err != nil {
		h.Log.Error("delete state after token store", "err", err)
		// Non-fatal: pending token is already stored. Log and continue.
	}

	// Build plugin redirect preserving existing query params.
	pluginURL, err := url.Parse(st.RedirectURI)
	if err != nil {
		writeError(w, http.StatusInternalServerError, "server_error", "invalid stored redirect_uri")
		return
	}
	pluginQ := pluginURL.Query()
	pluginQ.Set("code", brokerCode)
	pluginQ.Set("state", st.PluginState)
	pluginURL.RawQuery = pluginQ.Encode()

	http.Redirect(w, r, pluginURL.String(), http.StatusFound)
}

// Token redeems a broker code for tokens.
// POST /api/token (form: code, redirect_uri, site_url, plugin_version)
//
// The code posted here is a broker code generated by /api/callback, not a raw Google
// authorization code. The bridge redeems it from its pending-token store.
func (h *Handlers) Token(w http.ResponseWriter, r *http.Request) {
	r.Body = http.MaxBytesReader(w, r.Body, 8<<10)
	if err := r.ParseForm(); err != nil {
		writeError(w, http.StatusBadRequest, "invalid_form", "failed to parse form body")
		return
	}

	brokerCode := r.FormValue("code")
	if brokerCode == "" {
		writeError(w, http.StatusBadRequest, "missing_param", "code is required")
		return
	}

	// Log ancillary fields but ignore them.
	if siteURL := r.FormValue("site_url"); siteURL != "" {
		h.Log.Debug("token request", "site_url", siteURL)
	}

	pt, err := h.Store.TakePendingToken(r.Context(), brokerCode, h.Config.StateTTL)
	if err != nil {
		if errors.Is(err, store.ErrPendingTokenNotFound) {
			writeError(w, http.StatusForbidden, "invalid_code", "broker code not found, expired, or already used")
			return
		}
		h.Log.Error("take pending token", "err", err)
		writeError(w, http.StatusInternalServerError, "server_error", "failed to redeem broker code")
		return
	}

	writeJSON(w, http.StatusOK, map[string]any{
		"access_token":  pt.AccessToken,
		"refresh_token": pt.RefreshToken,
		"expires_in":    pt.ExpiresIn,
	})
}

// Refresh obtains a new access token from a refresh token.
// POST /api/refresh (form: refresh_token)
func (h *Handlers) Refresh(w http.ResponseWriter, r *http.Request) {
	r.Body = http.MaxBytesReader(w, r.Body, 8<<10)
	if err := r.ParseForm(); err != nil {
		writeError(w, http.StatusBadRequest, "invalid_form", "failed to parse form body")
		return
	}

	refreshToken := r.FormValue("refresh_token")
	if refreshToken == "" {
		writeError(w, http.StatusBadRequest, "missing_param", "refresh_token is required")
		return
	}

	token, err := h.Google.Refresh(r.Context(), refreshToken)
	if err != nil {
		h.Log.Error("refresh", "err", err)
		writeError(w, http.StatusBadGateway, "refresh_failed", "failed to refresh token")
		return
	}

	if token.AccessToken == "" {
		h.Log.Error("refresh returned empty access_token")
		writeError(w, http.StatusBadGateway, "incomplete_token", "Google returned empty access_token on refresh")
		return
	}

	resp := map[string]any{
		"access_token": token.AccessToken,
		"expires_in":   token.ExpiresIn,
	}
	if token.RefreshToken != "" {
		resp["refresh_token"] = token.RefreshToken
	}

	writeJSON(w, http.StatusOK, resp)
}

// Optimize is a deferred endpoint; v1 returns 501.
// POST /api/optimize
func (h *Handlers) Optimize(w http.ResponseWriter, r *http.Request) {
	writeError(w, http.StatusNotImplemented, "not_available", "image optimization not available")
}

const legalPageStyle = `<style>
:root{color-scheme:light}
*{box-sizing:border-box}
body{margin:0;background:#f5f6f8;color:#1f2329;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;line-height:1.65;-webkit-font-smoothing:antialiased}
.wrap{max-width:760px;margin:0 auto;padding:48px 24px 72px}
.card{background:#fff;border:1px solid #e3e6ea;border-radius:12px;padding:40px 44px;box-shadow:0 1px 3px rgba(16,24,40,.04)}
h1{font-size:1.6rem;line-height:1.25;margin:0 0 4px;color:#0f1115}
h2{font-size:1.1rem;margin:32px 0 8px;color:#0f1115}
p,li{font-size:1rem;color:#3a4049}
a{color:#2563eb;text-decoration:none}
a:hover{text-decoration:underline}
ul{padding-left:1.25rem}
li{margin:4px 0}
.eyebrow{font-size:.8rem;letter-spacing:.04em;text-transform:uppercase;color:#6b7280;font-weight:600;margin:0 0 16px}
.foot{margin-top:36px;padding-top:20px;border-top:1px solid #eceef1;font-size:.85rem;color:#6b7280}
</style>`

// setLegalPageHeaders writes baseline security/cache headers for static legal pages.
func setLegalPageHeaders(w http.ResponseWriter) {
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	w.Header().Set("Referrer-Policy", "no-referrer")
	w.Header().Set("Content-Security-Policy", "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'")
	w.Header().Set("Cache-Control", "public, max-age=3600")
}

// Terms serves the service terms page for the DraftSync OAuth bridge.
func (h *Handlers) Terms(w http.ResponseWriter, r *http.Request) {
	setLegalPageHeaders(w)
	w.WriteHeader(http.StatusOK)
	fmt.Fprint(w, `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>DraftSync OAuth Bridge — Terms of Service</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
`+legalPageStyle+`
</head>
<body>
<div class="wrap"><div class="card">
<p class="eyebrow">DraftSync OAuth Bridge</p>
<h1>DraftSync OAuth Bridge — Terms of Service</h1>
<p><strong>Effective date:</strong> 2026-06-10</p>
<p>The DraftSync OAuth bridge is operated by <strong>DraftSync</strong> to broker Google authentication for the DraftSync WordPress plugin.</p>
<h2>Purpose</h2>
<p>The service is provided solely to facilitate Google OAuth authorization, token exchange, and token refresh flows. It does not provide document import, document storage, or publishing functionality.</p>
<h2>Data processed</h2>
<ul>
<li>OAuth redirect metadata</li>
<li>OAuth state and authorization codes</li>
<li>access and refresh tokens</li>
<li>site URL and plugin version supplied by the WordPress plugin</li>
<li>basic request metadata, such as IP address and user-agent, for abuse mitigation and logging</li>
</ul>
<h2>What the bridge does not process</h2>
<ul>
<li>Google Docs content</li>
<li>WordPress post content</li>
<li>images</li>
<li>WordPress user passwords</li>
</ul>
<h2>Data retention</h2>
<p>Pending state and token rows are short-lived and are automatically pruned. Operational logs may be retained for security and troubleshooting.</p>
<h2>Acceptable use</h2>
<p>You may use the service only for DraftSync authentication flows. You must not use it to scrape, harvest credentials, resell access, interfere with Google services, or violate applicable law.</p>
<h2>Availability</h2>
<p>The service may be updated, suspended, or discontinued at any time.</p>
<h2>Disclaimer</h2>
<p>The service is provided on an "as is" and "as available" basis without warranties of any kind.</p>
<p class="foot"><strong>Contact</strong> — If you have questions, contact <a href="mailto:admin@draftsync.cortisol.icu">admin@draftsync.cortisol.icu</a>.</p>
</div></div>
</body>
</html>`)
}

// Privacy serves the privacy policy page for the DraftSync OAuth bridge.
func (h *Handlers) Privacy(w http.ResponseWriter, r *http.Request) {
	setLegalPageHeaders(w)
	w.WriteHeader(http.StatusOK)
	fmt.Fprint(w, `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>DraftSync OAuth Bridge — Privacy Policy</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
`+legalPageStyle+`
</head>
<body>
<div class="wrap"><div class="card">
<p class="eyebrow">DraftSync OAuth Bridge</p>
<h1>DraftSync OAuth Bridge — Privacy Policy</h1>
<p><strong>Effective date:</strong> 2026-06-10</p>
<p>This privacy policy describes how the DraftSync OAuth bridge collects and uses information.</p>
<h2>Information we collect</h2>
<ul>
<li>OAuth redirect metadata needed to complete authentication</li>
<li>OAuth state values, authorization codes, and tokens</li>
<li>site URL and plugin version submitted by the WordPress plugin</li>
<li>request metadata such as IP address and user-agent</li>
</ul>
<h2>How we use the information</h2>
<p>We use this information solely to broker Google authentication, exchange tokens, refresh tokens, and protect the service from abuse.</p>
<h2>What we do not collect</h2>
<ul>
<li>Google Docs content</li>
<li>WordPress post content</li>
<li>images or media library files</li>
<li>WordPress user passwords</li>
</ul>
<h2>Sharing</h2>
<p>We do not sell personal information. Information is shared only with Google as required to perform OAuth token exchange and refresh operations.</p>
<h2>Data retention</h2>
<p>Pending authentication rows are short-lived and automatically pruned. Security and operations logs may be retained as needed to keep the service reliable and secure.</p>
<h2>Security</h2>
<p>The service operates over HTTPS and limits stored pending data to short-lived authentication artifacts.</p>
<p class="foot"><strong>Contact</strong> — For privacy questions, contact <a href="mailto:admin@draftsync.cortisol.icu">admin@draftsync.cortisol.icu</a>.</p>
</div></div>
</body>
</html>`)
}

// redactToken returns a redacted representation of a token for safe logging.
// Shows length + last 4 chars max; empty string → "<empty>".
func redactToken(tok string) string {
	if tok == "" {
		return "<empty>"
	}
	if len(tok) <= 8 {
		return "len(" + itoa(len(tok)) + ")"
	}
	return "len(" + itoa(len(tok)) + "):..." + tok[len(tok)-4:]
}

// itoa is a minimal int→string helper (avoid importing strconv/fmt for this).
func itoa(n int) string {
	if n == 0 {
		return "0"
	}
	neg := false
	if n < 0 {
		neg = true
		n = -n
	}
	var buf [20]byte
	i := len(buf)
	for n > 0 {
		i--
		buf[i] = byte('0' + n%10)
		n /= 10
	}
	if neg {
		i--
		buf[i] = '-'
	}
	return string(buf[i:])
}
