package httpapi

import (
	"context"
	"encoding/json"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"strings"
	"testing"
	"time"

	"draftsync-bridge/internal/config"
	"draftsync-bridge/internal/oauth"
	"draftsync-bridge/internal/store"
)

// testEnv returns a fully wired Handlers with an in-memory SQLite store
// and a real config (empty allowlist).
func testEnv(t *testing.T) *Handlers {
	t.Helper()
	f, err := os.CreateTemp("", "draftsync-test-*.db")
	if err != nil {
		t.Fatal(err)
	}
	path := f.Name()
	f.Close()
	t.Cleanup(func() { os.Remove(path) })

	s, err := store.Open(path)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { s.Close() })

	return &Handlers{
		Store:  s,
		Google: oauth.NewGoogle("cid", "csecret", "https://bridge.example.com/api/callback"),
		Config: &config.C{
			PublicURL:              "https://bridge.example.com",
			StateTTL:               10 * time.Minute,
			AllowlistEmpty:         true,
			AllowAnyRedirectForDev: true,
		},
		Log: slog.New(slog.NewTextHandler(os.Stdout, &slog.HandlerOptions{Level: slog.LevelError})),
	}
}

// testEnvWithAllowlist returns handlers with a restricted redirect allowlist.
func testEnvWithAllowlist(t *testing.T) *Handlers {
	t.Helper()
	h := testEnv(t)
	h.Config.RedirectAllowlist = []string{"safe.local"}
	h.Config.AllowlistEmpty = false
	return h
}

// ── Healthz ──

func TestHealthz(t *testing.T) {
	h := testEnv(t)
	req := httptest.NewRequest(http.MethodGet, "/healthz", nil)
	w := httptest.NewRecorder()
	h.Healthz(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d", w.Code)
	}
	var body map[string]string
	if err := json.NewDecoder(w.Body).Decode(&body); err != nil {
		t.Fatal(err)
	}
	if body["status"] != "ok" {
		t.Fatalf("expected status ok, got %s", body["status"])
	}
}

// ── Auth ──

func TestAuthMissingRedirectURI(t *testing.T) {
	h := testEnv(t)
	req := httptest.NewRequest(http.MethodGet, "/api/auth?state=abc", nil)
	w := httptest.NewRecorder()
	h.Auth(w, req)

	if w.Code != http.StatusBadRequest {
		t.Fatalf("expected 400, got %d", w.Code)
	}
}

func TestAuthDisallowedRedirect(t *testing.T) {
	h := testEnvWithAllowlist(t)
	req := httptest.NewRequest(http.MethodGet, "/api/auth?redirect_uri=http://evil.local/cb&state=abc", nil)
	w := httptest.NewRecorder()
	h.Auth(w, req)

	if w.Code != http.StatusBadRequest {
		t.Fatalf("expected 400 for disallowed host, got %d", w.Code)
	}
}

func TestAuthRedirectsToGoogle(t *testing.T) {
	h := testEnv(t)
	req := httptest.NewRequest(http.MethodGet, "/api/auth?redirect_uri=http://safe.local/callback&state=plugin-state-1", nil)
	w := httptest.NewRecorder()
	h.Auth(w, req)

	if w.Code != http.StatusFound {
		t.Fatalf("expected 302, got %d", w.Code)
	}
	loc := w.Header().Get("Location")
	if !strings.HasPrefix(loc, "https://accounts.google.com/o/oauth2/v2/auth") {
		t.Fatalf("expected redirect to Google, got %s", loc)
	}
	if !strings.Contains(loc, "scope=https%3A%2F%2Fwww.googleapis.com%2Fauth%2Fdocuments.readonly") {
		t.Fatal("missing documents.readonly scope")
	}
	if !strings.Contains(loc, "access_type=offline") {
		t.Fatal("missing access_type=offline")
	}
	if !strings.Contains(loc, "prompt=consent") {
		t.Fatal("missing prompt=consent")
	}
}

// ── Callback ──

func TestCallbackUnknownStateForbidden(t *testing.T) {
	h := testEnv(t)
	req := httptest.NewRequest(http.MethodGet, "/api/callback?code=abc&state=unknown", nil)
	w := httptest.NewRecorder()
	h.Callback(w, req)

	if w.Code != http.StatusForbidden {
		t.Fatalf("expected 403 for unknown state, got %d", w.Code)
	}
}

func TestCallbackRedeemAndRedirect(t *testing.T) {
	// Set up a mock Google token server.
	mockGoogle := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]any{
			"access_token":  "mock-at",
			"refresh_token": "mock-rt",
			"expires_in":    3600,
		})
	}))
	defer mockGoogle.Close()

	h := testEnv(t)
	h.Google.SetTokenURL(mockGoogle.URL)
	ctx := context.Background()

	// Prime: put a state row.
	if err := h.Store.PutState(ctx, store.AuthState{
		BridgeState: "bridge-st-1",
		PluginState: "plugin-st-1",
		RedirectURI: "http://wp.local/admin.php?page=gdtg-settings&gdtg_saas_callback=1",
	}); err != nil {
		t.Fatal(err)
	}

	req := httptest.NewRequest(http.MethodGet, "/api/callback?code=gcode&state=bridge-st-1", nil)
	w := httptest.NewRecorder()
	h.Callback(w, req)

	if w.Code != http.StatusFound {
		t.Fatalf("expected 302, got %d", w.Code)
	}
	loc := w.Header().Get("Location")
	parsed, _ := url.Parse(loc)
	brokerCode := parsed.Query().Get("code")
	if brokerCode == "" || brokerCode == "gcode" {
		t.Fatalf("expected broker code (not raw google code) in redirect, got code=%s", brokerCode)
	}
	if parsed.Query().Get("state") != "plugin-st-1" {
		t.Fatalf("expected state=plugin-st-1 in redirect, got %s", loc)
	}
	// Preserves existing query params.
	if parsed.Query().Get("page") != "gdtg-settings" {
		t.Fatalf("expected page=gdtg-settings preserved, got %s", loc)
	}

	// Second call: single-use consumed — should 403.
	req2 := httptest.NewRequest(http.MethodGet, "/api/callback?code=gcode2&state=bridge-st-1", nil)
	w2 := httptest.NewRecorder()
	h.Callback(w2, req2)
	if w2.Code != http.StatusForbidden {
		t.Fatalf("expected 403 for replayed state, got %d", w2.Code)
	}
}

func TestTokenRedeemBrokerCode(t *testing.T) {
	// Set up a mock Google token server (needed for callback).
	mockGoogle := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]any{
			"access_token":  "real-at",
			"refresh_token": "real-rt",
			"expires_in":    7200,
		})
	}))
	defer mockGoogle.Close()

	h := testEnv(t)
	h.Google.SetTokenURL(mockGoogle.URL)
	ctx := context.Background()

	// Put state, trigger callback to get broker code.
	if err := h.Store.PutState(ctx, store.AuthState{
		BridgeState: "bridge-st-2",
		PluginState: "plugin-st-2",
		RedirectURI: "http://wp.local/admin.php?page=gdtg-settings&gdtg_saas_callback=1",
	}); err != nil {
		t.Fatal(err)
	}

	cbReq := httptest.NewRequest(http.MethodGet, "/api/callback?code=gcode&state=bridge-st-2", nil)
	cbW := httptest.NewRecorder()
	h.Callback(cbW, cbReq)

	if cbW.Code != http.StatusFound {
		t.Fatalf("callback: expected 302, got %d", cbW.Code)
	}
	loc := cbW.Header().Get("Location")
	parsed, _ := url.Parse(loc)
	brokerCode := parsed.Query().Get("code")

	// Redeem the broker code via /api/token.
	form := url.Values{"code": {brokerCode}, "redirect_uri": {"http://wp.local/cb"}}
	tokenReq := httptest.NewRequest(http.MethodPost, "/api/token", strings.NewReader(form.Encode()))
	tokenReq.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	tokenW := httptest.NewRecorder()
	h.Token(tokenW, tokenReq)

	if tokenW.Code != http.StatusOK {
		t.Fatalf("token: expected 200, got %d: %s", tokenW.Code, tokenW.Body.String())
	}
	var resp map[string]any
	if err := json.NewDecoder(tokenW.Body).Decode(&resp); err != nil {
		t.Fatal(err)
	}
	if resp["access_token"] != "real-at" {
		t.Fatalf("expected real-at, got %v", resp["access_token"])
	}
	if resp["refresh_token"] != "real-rt" {
		t.Fatalf("expected real-rt, got %v", resp["refresh_token"])
	}

	// Broker code is single-use.
	tokenReq2 := httptest.NewRequest(http.MethodPost, "/api/token", strings.NewReader(form.Encode()))
	tokenReq2.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	tokenW2 := httptest.NewRecorder()
	h.Token(tokenW2, tokenReq2)
	if tokenW2.Code != http.StatusForbidden {
		t.Fatalf("token replay: expected 403, got %d", tokenW2.Code)
	}
}

func TestTokenUnknownBrokerCodeForbidden(t *testing.T) {
	h := testEnv(t)
	form := url.Values{"code": {"unknown-broker-code"}}
	req := httptest.NewRequest(http.MethodPost, "/api/token", strings.NewReader(form.Encode()))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	w := httptest.NewRecorder()
	h.Token(w, req)

	if w.Code != http.StatusForbidden {
		t.Fatalf("expected 403 for unknown broker code, got %d", w.Code)
	}
}

// ── Token ──

func TestTokenMissingCode(t *testing.T) {
	h := testEnv(t)
	form := url.Values{"redirect_uri": {"http://x/cb"}}
	req := httptest.NewRequest(http.MethodPost, "/api/token", strings.NewReader(form.Encode()))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	w := httptest.NewRecorder()
	h.Token(w, req)

	if w.Code != http.StatusBadRequest {
		t.Fatalf("expected 400, got %d", w.Code)
	}
}

// ── Refresh ──

func TestRefreshMissingToken(t *testing.T) {
	h := testEnv(t)
	form := url.Values{}
	req := httptest.NewRequest(http.MethodPost, "/api/refresh", strings.NewReader(form.Encode()))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	w := httptest.NewRecorder()
	h.Refresh(w, req)

	if w.Code != http.StatusBadRequest {
		t.Fatalf("expected 400, got %d", w.Code)
	}
}

// ── Optimize ──

func TestOptimizeNotImplemented(t *testing.T) {
	h := testEnv(t)
	req := httptest.NewRequest(http.MethodPost, "/api/optimize", nil)
	w := httptest.NewRecorder()
	h.Optimize(w, req)

	if w.Code != http.StatusNotImplemented {
		t.Fatalf("expected 501, got %d", w.Code)
	}
}

func TestTermsPageHeaders(t *testing.T) {
	h := testEnv(t)
	req := httptest.NewRequest(http.MethodGet, "/terms", nil)
	w := httptest.NewRecorder()
	h.Terms(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d", w.Code)
	}
	if got := w.Header().Get("Content-Type"); got != "text/html; charset=utf-8" {
		t.Fatalf("expected html content type, got %q", got)
	}
	if got := w.Header().Get("X-Content-Type-Options"); got != "nosniff" {
		t.Fatalf("expected nosniff header, got %q", got)
	}
	if got := w.Header().Get("Referrer-Policy"); got != "no-referrer" {
		t.Fatalf("expected no-referrer policy, got %q", got)
	}
	if got := w.Header().Get("Cache-Control"); got != "public, max-age=3600" {
		t.Fatalf("expected cache header, got %q", got)
	}
	if got := w.Header().Get("Content-Security-Policy"); got != "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'" {
		t.Fatalf("unexpected csp header %q", got)
	}
	if !strings.Contains(w.Body.String(), "Terms of Service") {
		t.Fatal("expected terms body content")
	}
}

func TestPrivacyPageHeaders(t *testing.T) {
	h := testEnv(t)
	req := httptest.NewRequest(http.MethodGet, "/privacy", nil)
	w := httptest.NewRecorder()
	h.Privacy(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d", w.Code)
	}
	if got := w.Header().Get("Content-Type"); got != "text/html; charset=utf-8" {
		t.Fatalf("expected html content type, got %q", got)
	}
	if got := w.Header().Get("X-Content-Type-Options"); got != "nosniff" {
		t.Fatalf("expected nosniff header, got %q", got)
	}
	if got := w.Header().Get("Referrer-Policy"); got != "no-referrer" {
		t.Fatalf("expected no-referrer policy, got %q", got)
	}
	if got := w.Header().Get("Cache-Control"); got != "public, max-age=3600" {
		t.Fatalf("expected cache header, got %q", got)
	}
	if got := w.Header().Get("Content-Security-Policy"); got != "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'" {
		t.Fatalf("unexpected csp header %q", got)
	}
	if !strings.Contains(w.Body.String(), "Privacy Policy") {
		t.Fatal("expected privacy body content")
	}
}
