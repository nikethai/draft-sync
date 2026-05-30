package config

import (
	"testing"
)

func TestLoadMissingClientID(t *testing.T) {
	t.Setenv("BRIDGE_PUBLIC_URL", "https://bridge.example.com")
	t.Setenv("GOOGLE_CLIENT_SECRET", "s")

	_, err := Load()
	if err == nil || err.Error() == "" {
		t.Fatal("expected error for missing client_id")
	}
}

func TestLoadMissingClientSecret(t *testing.T) {
	t.Setenv("BRIDGE_PUBLIC_URL", "https://bridge.example.com")
	t.Setenv("GOOGLE_CLIENT_ID", "c")

	_, err := Load()
	if err == nil || err.Error() == "" {
		t.Fatal("expected error for missing client_secret")
	}
}

func TestLoadMissingPublicURL(t *testing.T) {
	t.Setenv("GOOGLE_CLIENT_ID", "c")
	t.Setenv("GOOGLE_CLIENT_SECRET", "s")

	_, err := Load()
	if err == nil || err.Error() == "" {
		t.Fatal("expected error for missing public_url")
	}
}

func TestLoadDefaultListenAddr(t *testing.T) {
	t.Setenv("BRIDGE_PUBLIC_URL", "https://bridge.example.com")
	t.Setenv("GOOGLE_CLIENT_ID", "c")
	t.Setenv("GOOGLE_CLIENT_SECRET", "s")
	t.Setenv("BRIDGE_REDIRECT_ALLOWLIST", "bridge.example.com")

	c, err := Load()
	if err != nil {
		t.Fatal(err)
	}
	if c.ListenAddr != "127.0.0.1:8080" {
		t.Fatalf("expected default listen addr, got %s", c.ListenAddr)
	}
}
func TestLoadStateTTLDefault(t *testing.T) {
	t.Setenv("BRIDGE_PUBLIC_URL", "https://bridge.example.com")
	t.Setenv("GOOGLE_CLIENT_ID", "c")
	t.Setenv("GOOGLE_CLIENT_SECRET", "s")
	t.Setenv("BRIDGE_REDIRECT_ALLOWLIST", "bridge.example.com")

	c, err := Load()
	if err != nil {
		t.Fatal(err)
	}
	if c.StateTTL.Seconds() < 1 {
		t.Fatalf("expected non-zero state TTL")
	}
}

func TestIsAllowedRedirectEmptyAllowlist(t *testing.T) {
	c := &C{AllowlistEmpty: true, AllowAnyRedirectForDev: true}
	if err := c.IsAllowedRedirect("http://any-host.local/cb"); err != nil {
		t.Fatal(err)
	}
}

func TestIsAllowedRedirectMatchesHost(t *testing.T) {
	c := &C{RedirectAllowlist: []string{"draftsync.local", "example.com"}}
	if err := c.IsAllowedRedirect("http://draftsync.local/callback?x=1"); err != nil {
		t.Fatal(err)
	}
	if err := c.IsAllowedRedirect("https://example.com/cb"); err != nil {
		t.Fatal(err)
	}
}

func TestIsAllowedRedirectHostNotAllowed(t *testing.T) {
	c := &C{RedirectAllowlist: []string{"approved.local"}}
	if err := c.IsAllowedRedirect("http://evil.local/cb"); err == nil {
		t.Fatal("expected error for disallowed host")
	}
}

func TestIsAllowedRedirectInvalidScheme(t *testing.T) {
	c := &C{RedirectAllowlist: []string{"x.local"}}
	if err := c.IsAllowedRedirect("ftp://x.local/cb"); err == nil {
		t.Fatal("expected error for ftp scheme")
	}
}

func TestIsAllowedRedirectNoHost(t *testing.T) {
	c := &C{RedirectAllowlist: []string{"x.local"}}
	if err := c.IsAllowedRedirect("/relative"); err == nil {
		t.Fatal("expected error for relative URL")
	}
}

func TestGoogleCallback(t *testing.T) {
	c := &C{PublicURL: "https://bridge.example.com/"}
	if cb := c.GoogleCallback(); cb != "https://bridge.example.com/api/callback" {
		t.Fatalf("expected callback, got %s", cb)
	}
}

func TestGoogleCallbackNoTrailingSlash(t *testing.T) {
	c := &C{PublicURL: "https://bridge.example.com"}
	if cb := c.GoogleCallback(); cb != "https://bridge.example.com/api/callback" {
		t.Fatalf("expected callback, got %s", cb)
	}
}

func TestEmptyAllowlistWithoutDevFlag(t *testing.T) {
	t.Setenv("BRIDGE_PUBLIC_URL", "https://bridge.example.com")
	t.Setenv("GOOGLE_CLIENT_ID", "c")
	t.Setenv("GOOGLE_CLIENT_SECRET", "s")
	// No BRIDGE_REDIRECT_ALLOWLIST and no dev flag.

	_, err := Load()
	if err == nil {
		t.Fatal("expected error for empty allowlist without dev flag")
	}
}

func TestEmptyAllowlistWithDevFlag(t *testing.T) {
	t.Setenv("BRIDGE_PUBLIC_URL", "https://bridge.example.com")
	t.Setenv("GOOGLE_CLIENT_ID", "c")
	t.Setenv("GOOGLE_CLIENT_SECRET", "s")
	t.Setenv("BRIDGE_ALLOW_ANY_REDIRECT_FOR_DEV", "1")

	c, err := Load()
	if err != nil {
		t.Fatal(err)
	}
	if !c.AllowlistEmpty {
		t.Fatal("expected AllowlistEmpty=true")
	}
	if !c.AllowAnyRedirectForDev {
		t.Fatal("expected AllowAnyRedirectForDev=true")
	}
}

func TestPublicURLNoHost(t *testing.T) {
	t.Setenv("BRIDGE_PUBLIC_URL", "https:///path-only")
	t.Setenv("GOOGLE_CLIENT_ID", "c")
	t.Setenv("GOOGLE_CLIENT_SECRET", "s")
	t.Setenv("BRIDGE_REDIRECT_ALLOWLIST", "x")

	_, err := Load()
	if err == nil {
		t.Fatal("expected error for public URL without host")
	}
}

func TestPublicURLHTTPNonLocal(t *testing.T) {
	t.Setenv("BRIDGE_PUBLIC_URL", "http://bridge.example.com")
	t.Setenv("GOOGLE_CLIENT_ID", "c")
	t.Setenv("GOOGLE_CLIENT_SECRET", "s")
	t.Setenv("BRIDGE_REDIRECT_ALLOWLIST", "x")

	_, err := Load()
	if err == nil {
		t.Fatal("expected error for http non-localhost public URL")
	}
}

func TestPublicURLHTTPLocalhostWithDevFlag(t *testing.T) {
	t.Setenv("BRIDGE_PUBLIC_URL", "http://localhost:8080")
	t.Setenv("GOOGLE_CLIENT_ID", "c")
	t.Setenv("GOOGLE_CLIENT_SECRET", "s")
	t.Setenv("BRIDGE_ALLOW_ANY_REDIRECT_FOR_DEV", "1")

	c, err := Load()
	if err != nil {
		t.Fatal(err)
	}
	if c.PublicURL != "http://localhost:8080" {
		t.Fatalf("expected http://localhost:8080, got %s", c.PublicURL)
	}
}

func TestPublicURLHTTPLocalhostWithoutDevFlag(t *testing.T) {
	t.Setenv("BRIDGE_PUBLIC_URL", "http://localhost:8080")
	t.Setenv("GOOGLE_CLIENT_ID", "c")
	t.Setenv("GOOGLE_CLIENT_SECRET", "s")
	t.Setenv("BRIDGE_REDIRECT_ALLOWLIST", "x")

	_, err := Load()
	if err == nil {
		t.Fatal("expected error for http localhost without dev flag")
	}
}

func TestStateTTLZero(t *testing.T) {
	t.Setenv("BRIDGE_PUBLIC_URL", "https://bridge.example.com")
	t.Setenv("GOOGLE_CLIENT_ID", "c")
	t.Setenv("GOOGLE_CLIENT_SECRET", "s")
	t.Setenv("BRIDGE_REDIRECT_ALLOWLIST", "x")
	t.Setenv("BRIDGE_STATE_TTL", "0s")

	_, err := Load()
	if err == nil {
		t.Fatal("expected error for zero state TTL")
	}
}

func TestStateTTLNegative(t *testing.T) {
	t.Setenv("BRIDGE_PUBLIC_URL", "https://bridge.example.com")
	t.Setenv("GOOGLE_CLIENT_ID", "c")
	t.Setenv("GOOGLE_CLIENT_SECRET", "s")
	t.Setenv("BRIDGE_REDIRECT_ALLOWLIST", "x")
	t.Setenv("BRIDGE_STATE_TTL", "-5m")

	_, err := Load()
	if err == nil {
		t.Fatal("expected error for negative state TTL")
	}
}

func TestStateTTLOverMax(t *testing.T) {
	t.Setenv("BRIDGE_PUBLIC_URL", "https://bridge.example.com")
	t.Setenv("GOOGLE_CLIENT_ID", "c")
	t.Setenv("GOOGLE_CLIENT_SECRET", "s")
	t.Setenv("BRIDGE_REDIRECT_ALLOWLIST", "x")
	t.Setenv("BRIDGE_STATE_TTL", "31m")

	_, err := Load()
	if err == nil {
		t.Fatal("expected error for state TTL over 30 minutes")
	}
}

func TestIsAllowedRedirectEmptyAllowlistNoDevFlag(t *testing.T) {
	c := &C{AllowlistEmpty: true, AllowAnyRedirectForDev: false}
	if err := c.IsAllowedRedirect("http://any-host.local/cb"); err == nil {
		t.Fatal("expected error for empty allowlist without dev flag")
	}
}
