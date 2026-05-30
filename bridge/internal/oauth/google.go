package oauth

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// Token represents an OAuth 2.0 token pair returned by Google.
type Token struct {
	AccessToken  string `json:"access_token"`
	RefreshToken string `json:"refresh_token"`
	ExpiresIn    int    `json:"expires_in"`
}

// Google provides OAuth 2.0 authorize, code-exchange, and refresh flows.
type Google struct {
	clientID     string
	clientSecret string
	callbackURL  string
	httpClient   *http.Client
	tokenURL     string
}

// NewGoogle creates a Google OAuth client.
func NewGoogle(clientID, clientSecret, callbackURL string) *Google {
	return &Google{
		clientID:     clientID,
		clientSecret: clientSecret,
		callbackURL:  callbackURL,
		httpClient:   &http.Client{Timeout: 15 * time.Second},
		tokenURL:     "https://oauth2.googleapis.com/token",
	}
}

// AuthorizeURL builds the Google OAuth consent URL for the given state.
func (g *Google) AuthorizeURL(bridgeState string) string {
	v := url.Values{
		"client_id":     {g.clientID},
		"redirect_uri":  {g.callbackURL},
		"response_type": {"code"},
		"scope":         {"https://www.googleapis.com/auth/documents.readonly https://www.googleapis.com/auth/drive.readonly"},
		"access_type":   {"offline"},
		"prompt":        {"consent"},
		"state":         {bridgeState},
	}
	return "https://accounts.google.com/o/oauth2/v2/auth?" + v.Encode()
}

// ExchangeCode exchanges an authorization code for tokens.
func (g *Google) ExchangeCode(ctx context.Context, code string) (Token, error) {
	return g.tokenPost(ctx, url.Values{
		"code":          {code},
		"client_id":     {g.clientID},
		"client_secret": {g.clientSecret},
		"redirect_uri":  {g.callbackURL},
		"grant_type":    {"authorization_code"},
	})
}

// Refresh uses a refresh token to obtain a new access token.
func (g *Google) Refresh(ctx context.Context, refreshToken string) (Token, error) {
	return g.tokenPost(ctx, url.Values{
		"refresh_token": {refreshToken},
		"client_id":     {g.clientID},
		"client_secret": {g.clientSecret},
		"grant_type":    {"refresh_token"},
	})
}

// SetHTTPClient allows overriding the HTTP client (for tests).
func (g *Google) SetHTTPClient(c *http.Client) { g.httpClient = c }

// SetTokenURL allows overriding the token endpoint URL (for tests).
func (g *Google) SetTokenURL(u string) { g.tokenURL = u }

func (g *Google) tokenPost(ctx context.Context, form url.Values) (Token, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodPost,
		g.tokenURL,
		strings.NewReader(form.Encode()))
	if err != nil {
		return Token{}, fmt.Errorf("token request: %w", err)
	}
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")

	resp, err := g.httpClient.Do(req)
	if err != nil {
		return Token{}, fmt.Errorf("token exchange: %w", err)
	}
	defer func() { _ = resp.Body.Close() }()

	body, err := io.ReadAll(io.LimitReader(resp.Body, 16<<10))
	if err != nil {
		return Token{}, fmt.Errorf("read token response: %w", err)
	}

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return Token{}, fmt.Errorf("google token endpoint returned %d: %s", resp.StatusCode, string(body))
	}

	var t Token
	if err := json.Unmarshal(body, &t); err != nil {
		return Token{}, fmt.Errorf("parse token: %w", err)
	}
	if t.AccessToken == "" {
		return Token{}, fmt.Errorf("google returned empty access_token")
	}
	if t.ExpiresIn == 0 {
		t.ExpiresIn = 3600
	}
	return t, nil
}
