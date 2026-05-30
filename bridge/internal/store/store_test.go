package store

import (
	"context"
	"os"
	"testing"
	"time"
)

func tempDB(t *testing.T) *S {
	t.Helper()
	f, err := os.CreateTemp("", "draftsync-test-*.db")
	if err != nil {
		t.Fatal(err)
	}
	path := f.Name()
	f.Close()
	s, err := Open(path)
	if err != nil {
		os.Remove(path)
		t.Fatal(err)
	}
	t.Cleanup(func() {
		s.Close()
		os.Remove(path)
	})
	return s
}

func TestPutTakeRoundTrip(t *testing.T) {
	s := tempDB(t)
	ctx := context.Background()

	st := AuthState{
		BridgeState: "bridge-abc",
		PluginState: "plugin-xyz",
		RedirectURI: "http://wp.local/cb",
	}
	if err := s.PutState(ctx, st); err != nil {
		t.Fatal(err)
	}

	got, err := s.TakeState(ctx, "bridge-abc", 10*time.Minute)
	if err != nil {
		t.Fatal(err)
	}
	if got.PluginState != "plugin-xyz" {
		t.Fatalf("expected plugin-xyz, got %s", got.PluginState)
	}
	if got.RedirectURI != "http://wp.local/cb" {
		t.Fatalf("expected redirect_uri, got %s", got.RedirectURI)
	}
}

func TestTakeStateSingleUse(t *testing.T) {
	s := tempDB(t)
	ctx := context.Background()

	if err := s.PutState(ctx, AuthState{
		BridgeState: "key1",
		PluginState: "ps",
		RedirectURI: "http://x/cb",
	}); err != nil {
		t.Fatal(err)
	}

	if _, err := s.TakeState(ctx, "key1", 10*time.Minute); err != nil {
		t.Fatal(err)
	}
	// Second attempt must return ErrStateNotFound.
	_, err := s.TakeState(ctx, "key1", 10*time.Minute)
	if err != ErrStateNotFound {
		t.Fatalf("expected ErrStateNotFound on second take, got %v", err)
	}
}

func TestTakeStateNotFound(t *testing.T) {
	s := tempDB(t)
	ctx := context.Background()

	_, err := s.TakeState(ctx, "nonexistent", 10*time.Minute)
	if err != ErrStateNotFound {
		t.Fatalf("expected ErrStateNotFound, got %v", err)
	}
}

func TestTakeStateExpired(t *testing.T) {
	s := tempDB(t)
	ctx := context.Background()

	if err := s.PutState(ctx, AuthState{
		BridgeState: "key2",
		PluginState: "ps",
		RedirectURI: "http://x/cb",
		CreatedAt:   time.Now().Add(-1 * time.Hour),
	}); err != nil {
		t.Fatal(err)
	}

	_, err := s.TakeState(ctx, "key2", 10*time.Minute)
	if err != ErrStateNotFound {
		t.Fatalf("expected ErrStateNotFound for expired state, got %v", err)
	}
}

func TestPruneExpired(t *testing.T) {
	s := tempDB(t)
	ctx := context.Background()

	for i, age := range []time.Duration{-31 * time.Minute, -5 * time.Minute, 0} {
		if err := s.PutState(ctx, AuthState{
			BridgeState: "pk" + string(rune('0'+i)),
			PluginState: "ps",
			RedirectURI: "http://x/cb",
			CreatedAt:   time.Now().Add(age),
		}); err != nil {
			t.Fatal(err)
		}
	}

	n, err := s.PruneExpired(ctx, 10*time.Minute)
	if err != nil {
		t.Fatal(err)
	}
	if n != 1 {
		t.Fatalf("expected 1 pruned, got %d", n)
	}
}

func TestPendingTokenRoundTrip(t *testing.T) {
	s := tempDB(t)
	ctx := context.Background()

	pt := PendingToken{
		BrokerCode:   "broker-abc",
		AccessToken:  "at-123",
		RefreshToken: "rt-456",
		ExpiresIn:    3600,
	}
	if err := s.PutPendingToken(ctx, pt); err != nil {
		t.Fatal(err)
	}

	got, err := s.TakePendingToken(ctx, "broker-abc", 10*time.Minute)
	if err != nil {
		t.Fatal(err)
	}
	if got.AccessToken != "at-123" {
		t.Fatalf("expected at-123, got %s", got.AccessToken)
	}
	if got.RefreshToken != "rt-456" {
		t.Fatalf("expected rt-456, got %s", got.RefreshToken)
	}
	if got.ExpiresIn != 3600 {
		t.Fatalf("expected 3600, got %d", got.ExpiresIn)
	}
}

func TestPendingTokenSingleUse(t *testing.T) {
	s := tempDB(t)
	ctx := context.Background()

	if err := s.PutPendingToken(ctx, PendingToken{
		BrokerCode:   "broker-single",
		AccessToken:  "at",
		RefreshToken: "rt",
		ExpiresIn:    3600,
	}); err != nil {
		t.Fatal(err)
	}

	if _, err := s.TakePendingToken(ctx, "broker-single", 10*time.Minute); err != nil {
		t.Fatal(err)
	}
	_, err := s.TakePendingToken(ctx, "broker-single", 10*time.Minute)
	if err != ErrPendingTokenNotFound {
		t.Fatalf("expected ErrPendingTokenNotFound on second take, got %v", err)
	}
}

func TestPendingTokenNotFound(t *testing.T) {
	s := tempDB(t)
	ctx := context.Background()

	_, err := s.TakePendingToken(ctx, "nonexistent", 10*time.Minute)
	if err != ErrPendingTokenNotFound {
		t.Fatalf("expected ErrPendingTokenNotFound, got %v", err)
	}
}

func TestPendingTokenExpired(t *testing.T) {
	s := tempDB(t)
	ctx := context.Background()

	if err := s.PutPendingToken(ctx, PendingToken{
		BrokerCode:   "broker-exp",
		AccessToken:  "at",
		RefreshToken: "rt",
		ExpiresIn:    3600,
		CreatedAt:    time.Now().Add(-1 * time.Hour),
	}); err != nil {
		t.Fatal(err)
	}

	_, err := s.TakePendingToken(ctx, "broker-exp", 10*time.Minute)
	if err != ErrPendingTokenNotFound {
		t.Fatalf("expected ErrPendingTokenNotFound for expired token, got %v", err)
	}
}

func TestPruneExpiredIncludesPendingTokens(t *testing.T) {
	s := tempDB(t)
	ctx := context.Background()

	// Put an old pending token.
	if err := s.PutPendingToken(ctx, PendingToken{
		BrokerCode:   "old-broker",
		AccessToken:  "at",
		RefreshToken: "rt",
		ExpiresIn:    3600,
		CreatedAt:    time.Now().Add(-31 * time.Minute),
	}); err != nil {
		t.Fatal(err)
	}

	n, err := s.PruneExpired(ctx, 10*time.Minute)
	if err != nil {
		t.Fatal(err)
	}
	if n < 1 {
		t.Fatalf("expected at least 1 pruned (pending_token), got %d", n)
	}
}
