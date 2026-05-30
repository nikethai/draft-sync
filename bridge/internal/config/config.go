package config

import (
	"fmt"
	"net/url"
	"os"
	"strings"
	"time"
)

// C holds the parsed, validated bridge configuration.
type C struct {
	ListenAddr             string
	PublicURL              string
	GoogleClientID         string
	GoogleClientSecret     string
	DBPath                 string
	RedirectAllowlist      []string
	StateTTL               time.Duration
	AllowlistEmpty         bool
	AllowAnyRedirectForDev bool
}

// Load reads configuration from environment variables and validates.
func Load() (*C, error) {
	c := &C{
		ListenAddr:             envOrDefault("BRIDGE_LISTEN_ADDR", "127.0.0.1:8080"),
		PublicURL:              os.Getenv("BRIDGE_PUBLIC_URL"),
		GoogleClientID:         os.Getenv("GOOGLE_CLIENT_ID"),
		GoogleClientSecret:     os.Getenv("GOOGLE_CLIENT_SECRET"),
		DBPath:                 envOrDefault("BRIDGE_DB_PATH", "/opt/draftsync-bridge/data/bridge.db"),
		StateTTL:               parseDuration(envOrDefault("BRIDGE_STATE_TTL", "10m"), 10*time.Minute),
		AllowAnyRedirectForDev: os.Getenv("BRIDGE_ALLOW_ANY_REDIRECT_FOR_DEV") == "1",
	}
	rawAllowlist := os.Getenv("BRIDGE_REDIRECT_ALLOWLIST")
	if rawAllowlist == "" {
		c.AllowlistEmpty = true
	} else {
		for _, entry := range strings.Split(rawAllowlist, ",") {
			entry = strings.TrimSpace(entry)
			if entry != "" {
				c.RedirectAllowlist = append(c.RedirectAllowlist, entry)
			}
		}
	}
	return c, c.validate()
}

func (c *C) validate() error {
	if c.GoogleClientID == "" {
		return fmt.Errorf("GOOGLE_CLIENT_ID is required")
	}
	if c.GoogleClientSecret == "" {
		return fmt.Errorf("GOOGLE_CLIENT_SECRET is required")
	}
	if c.PublicURL == "" {
		return fmt.Errorf("BRIDGE_PUBLIC_URL is required")
	}
	u, err := url.Parse(c.PublicURL)
	if err != nil {
		return fmt.Errorf("BRIDGE_PUBLIC_URL is not a valid URL: %w", err)
	}
	if u.Host == "" {
		return fmt.Errorf("BRIDGE_PUBLIC_URL has no host")
	}
	if u.Scheme != "https" {
		isLocal := u.Hostname() == "localhost" || u.Hostname() == "127.0.0.1" || u.Hostname() == "::1"
		if u.Scheme != "http" || !isLocal {
			return fmt.Errorf("BRIDGE_PUBLIC_URL must use https (http only allowed for localhost in dev mode)")
		}
		if !c.AllowAnyRedirectForDev {
			return fmt.Errorf("BRIDGE_PUBLIC_URL uses http on localhost — set BRIDGE_ALLOW_ANY_REDIRECT_FOR_DEV=1 for dev mode")
		}
	}
	if c.AllowlistEmpty && !c.AllowAnyRedirectForDev {
		return fmt.Errorf("BRIDGE_REDIRECT_ALLOWLIST is empty — set BRIDGE_ALLOW_ANY_REDIRECT_FOR_DEV=1 for dev mode or configure an allowlist")
	}
	if c.StateTTL < time.Minute {
		return fmt.Errorf("BRIDGE_STATE_TTL must be at least 1 minute, got %s", c.StateTTL)
	}
	if c.StateTTL > 30*time.Minute {
		return fmt.Errorf("BRIDGE_STATE_TTL must be at most 30 minutes, got %s", c.StateTTL)
	}
	return nil
}

// AllowlistEmpty reports whether no redirect allowlist was configured (dev mode only).
func (c *C) IsAllowlistEmpty() bool { return c.AllowlistEmpty }

// IsAllowedRedirect checks whether the given redirect_uri is permitted.
func (c *C) IsAllowedRedirect(rawURI string) error {
	u, err := url.Parse(rawURI)
	if err != nil {
		return fmt.Errorf("redirect_uri parse error: %w", err)
	}
	if u.Scheme != "http" && u.Scheme != "https" {
		return fmt.Errorf("redirect_uri scheme must be http or https")
	}
	if u.Host == "" {
		return fmt.Errorf("redirect_uri has no host")
	}
	if c.AllowlistEmpty && c.AllowAnyRedirectForDev {
		return nil
	}
	for _, entry := range c.RedirectAllowlist {
		if u.Host == entry {
			return nil
		}
		if (entry == "http://"+u.Host) || (entry == "https://"+u.Host) {
			return nil
		}
	}
	return fmt.Errorf("redirect_uri host %q is not in the allowlist", u.Host)
}

// GoogleCallback returns the registered redirect_uri for Google OAuth.
func (c *C) GoogleCallback() string {
	return strings.TrimRight(c.PublicURL, "/") + "/api/callback"
}

func envOrDefault(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}

func parseDuration(s string, def time.Duration) time.Duration {
	d, err := time.ParseDuration(s)
	if err != nil {
		return def
	}
	return d
}
