package oauth

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestAuthorizeURLContainsRequiredParams(t *testing.T) {
	g := NewGoogle("cid", "csecret", "https://b.example.com/api/callback")
	u := g.AuthorizeURL("bridge-state-123")

	for _, want := range []string{
		"client_id=cid",
		"redirect_uri=https%3A%2F%2Fb.example.com%2Fapi%2Fcallback",
		"response_type=code",
		"scope=https%3A%2F%2Fwww.googleapis.com%2Fauth%2Fdocuments.readonly",
		"access_type=offline",
		"prompt=consent",
		"state=bridge-state-123",
	} {
		if !strings.Contains(u, want) {
			t.Fatalf("missing %q in authorize URL", want)
		}
	}
	if !strings.HasPrefix(u, "https://accounts.google.com/o/oauth2/v2/auth?") {
		t.Fatalf("wrong base URL: %s", u)
	}
}

func TestExchangeCodeHappyPath(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			t.Errorf("expected POST, got %s", r.Method)
		}
		json.NewEncoder(w).Encode(map[string]any{
			"access_token":  "at-xyz",
			"refresh_token": "rt-xyz",
			"expires_in":    3600,
		})
	}))
	defer srv.Close()

	g := NewGoogle("cid", "csecret", "https://b.example.com/api/callback")
	g.httpClient = srv.Client()
	// Override the token endpoint to our test server.
	g.httpClient.Transport = &fixedURLTransport{base: srv.URL}

	tok, err := g.ExchangeCode(t.Context(), "authcode123")
	if err != nil {
		t.Fatal(err)
	}
	if tok.AccessToken != "at-xyz" || tok.RefreshToken != "rt-xyz" {
		t.Fatalf("unexpected token: %+v", tok)
	}
}

func TestExchangeCodeEmptyAccessToken(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		json.NewEncoder(w).Encode(map[string]any{
			"access_token":  "",
			"refresh_token": "rt",
		})
	}))
	defer srv.Close()

	g := NewGoogle("cid", "csecret", "cb")
	g.httpClient = srv.Client()
	g.httpClient.Transport = &fixedURLTransport{base: srv.URL}

	_, err := g.ExchangeCode(t.Context(), "code")
	if err == nil {
		t.Fatal("expected error for empty access_token")
	}
}

func TestRefreshHappyPath(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = r.ParseForm()
		if r.FormValue("grant_type") != "refresh_token" {
			t.Errorf("expected refresh_token grant, got %q", r.FormValue("grant_type"))
		}
		json.NewEncoder(w).Encode(map[string]any{
			"access_token": "at-new",
			"expires_in":   3600,
		})
	}))
	defer srv.Close()

	g := NewGoogle("cid", "csecret", "cb")
	g.httpClient = srv.Client()
	g.httpClient.Transport = &fixedURLTransport{base: srv.URL}

	tok, err := g.Refresh(t.Context(), "rt-old")
	if err != nil {
		t.Fatal(err)
	}
	if tok.AccessToken != "at-new" {
		t.Fatalf("expected at-new, got %s", tok.AccessToken)
	}
}

func TestRefreshNon200(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusBadRequest)
		w.Write([]byte(`{"error":"invalid_grant"}`))
	}))
	defer srv.Close()

	g := NewGoogle("cid", "csecret", "cb")
	g.httpClient = srv.Client()
	g.httpClient.Transport = &fixedURLTransport{base: srv.URL}

	_, err := g.Refresh(t.Context(), "rt-bad")
	if err == nil {
		t.Fatal("expected error for non-200 refresh")
	}
}

// fixedURLTransport rewrites all requests to a fixed base URL.
type fixedURLTransport struct {
	base string
}

func (t *fixedURLTransport) RoundTrip(r *http.Request) (*http.Response, error) {
	r.URL.Scheme = "http"
	r.URL.Host = strings.TrimPrefix(t.base, "http://")
	return http.DefaultTransport.RoundTrip(r)
}
