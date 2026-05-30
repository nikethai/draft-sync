package store

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"time"

	_ "modernc.org/sqlite"
)

// ErrStateNotFound is returned by TakeState when the state key is absent or expired.
var ErrStateNotFound = errors.New("auth state not found or already consumed")

// ErrPendingTokenNotFound is returned by TakePendingToken when the broker code is absent or expired.
var ErrPendingTokenNotFound = errors.New("pending token not found or already consumed")

// PendingToken holds a broker-code-bound token bundle awaiting plugin redemption.
type PendingToken struct {
	BrokerCode   string
	AccessToken  string
	RefreshToken string
	ExpiresIn    int
	CreatedAt    time.Time
}

// AuthState is a persisted pending OAuth authorization state.
type AuthState struct {
	BridgeState string
	PluginState string
	RedirectURI string
	CreatedAt   time.Time
}

// S wraps a SQLite connection pool for bridge state storage.
type S struct {
	db *sql.DB
}

// Open opens (or creates) the SQLite database at path and ensures schema.
func Open(path string) (*S, error) {
	db, err := sql.Open("sqlite", path+"?_journal_mode=WAL&_busy_timeout=5000")
	if err != nil {
		return nil, fmt.Errorf("open sqlite: %w", err)
	}
	s := &S{db: db}
	if err := s.migrate(); err != nil {
		db.Close()
		return nil, fmt.Errorf("migrate: %w", err)
	}
	return s, nil
}

func (s *S) migrate() error {
	if _, err := s.db.Exec(`CREATE TABLE IF NOT EXISTS auth_state (
		bridge_state TEXT PRIMARY KEY,
		plugin_state TEXT NOT NULL,
		redirect_uri TEXT NOT NULL,
		created_at   INTEGER NOT NULL
	)`); err != nil {
		return err
	}
	_, err := s.db.Exec(`CREATE TABLE IF NOT EXISTS pending_token (
		broker_code   TEXT PRIMARY KEY,
		access_token  TEXT NOT NULL,
		refresh_token TEXT NOT NULL,
		expires_in    INTEGER NOT NULL,
		created_at    INTEGER NOT NULL
	)`)
	return err
}

// PutState inserts a new pending auth state row.
func (s *S) PutState(ctx context.Context, st AuthState) error {
	if st.CreatedAt.IsZero() {
		st.CreatedAt = time.Now()
	}
	_, err := s.db.ExecContext(ctx,
		`INSERT INTO auth_state (bridge_state, plugin_state, redirect_uri, created_at) VALUES (?, ?, ?, ?)`,
		st.BridgeState, st.PluginState, st.RedirectURI, st.CreatedAt.Unix(),
	)
	return err
}

// TakeState atomically fetches and deletes the auth state row.
// Returns ErrStateNotFound if key is absent, expired, or already consumed.
func (s *S) TakeState(ctx context.Context, bridgeState string, ttl time.Duration) (AuthState, error) {
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return AuthState{}, err
	}
	defer tx.Rollback()

	row := tx.QueryRowContext(ctx, `SELECT bridge_state, plugin_state, redirect_uri, created_at FROM auth_state WHERE bridge_state = ?`, bridgeState)
	var st AuthState
	var unix int64
	if err := row.Scan(&st.BridgeState, &st.PluginState, &st.RedirectURI, &unix); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return AuthState{}, ErrStateNotFound
		}
		return AuthState{}, err
	}
	st.CreatedAt = time.Unix(unix, 0)

	// TTL enforcement — expire even if the row existed.
	if time.Since(st.CreatedAt) > ttl {
		// Clean up the expired row.
		_, _ = tx.ExecContext(ctx, `DELETE FROM auth_state WHERE bridge_state = ?`, bridgeState)
		if err := tx.Commit(); err != nil {
			return AuthState{}, err
		}
		return AuthState{}, ErrStateNotFound
	}

	// Single-use: delete the consumed row.
	if _, err := tx.ExecContext(ctx, `DELETE FROM auth_state WHERE bridge_state = ?`, bridgeState); err != nil {
		return AuthState{}, err
	}
	if err := tx.Commit(); err != nil {
		return AuthState{}, err
	}
	return st, nil
}

// GetState reads an auth state row without consuming it.
// Returns ErrStateNotFound if the key is absent or expired (expired rows are cleaned up).
func (s *S) GetState(ctx context.Context, bridgeState string, ttl time.Duration) (AuthState, error) {
	row := s.db.QueryRowContext(ctx, `SELECT bridge_state, plugin_state, redirect_uri, created_at FROM auth_state WHERE bridge_state = ?`, bridgeState)
	var st AuthState
	var unix int64
	if err := row.Scan(&st.BridgeState, &st.PluginState, &st.RedirectURI, &unix); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return AuthState{}, ErrStateNotFound
		}
		return AuthState{}, err
	}
	st.CreatedAt = time.Unix(unix, 0)

	if time.Since(st.CreatedAt) > ttl {
		// Clean up the expired row.
		_, _ = s.db.ExecContext(ctx, `DELETE FROM auth_state WHERE bridge_state = ?`, bridgeState)
		return AuthState{}, ErrStateNotFound
	}
	return st, nil
}

// DeleteState deletes a consumed auth state row. Returns ErrStateNotFound if already gone.
func (s *S) DeleteState(ctx context.Context, bridgeState string) error {
	res, err := s.db.ExecContext(ctx, `DELETE FROM auth_state WHERE bridge_state = ?`, bridgeState)
	if err != nil {
		return err
	}
	n, _ := res.RowsAffected()
	if n == 0 {
		return ErrStateNotFound
	}
	return nil
}

// PutPendingToken stores a broker code → token mapping for later redemption.
func (s *S) PutPendingToken(ctx context.Context, pt PendingToken) error {
	if pt.CreatedAt.IsZero() {
		pt.CreatedAt = time.Now()
	}
	_, err := s.db.ExecContext(ctx,
		`INSERT INTO pending_token (broker_code, access_token, refresh_token, expires_in, created_at) VALUES (?, ?, ?, ?, ?)`,
		pt.BrokerCode, pt.AccessToken, pt.RefreshToken, pt.ExpiresIn, pt.CreatedAt.Unix(),
	)
	return err
}

// TakePendingToken atomically fetches and deletes the pending token row.
// Returns ErrPendingTokenNotFound if key is absent, expired, or already consumed.
func (s *S) TakePendingToken(ctx context.Context, brokerCode string, ttl time.Duration) (PendingToken, error) {
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return PendingToken{}, err
	}
	defer tx.Rollback()

	row := tx.QueryRowContext(ctx, `SELECT broker_code, access_token, refresh_token, expires_in, created_at FROM pending_token WHERE broker_code = ?`, brokerCode)
	var pt PendingToken
	var unix int64
	if err := row.Scan(&pt.BrokerCode, &pt.AccessToken, &pt.RefreshToken, &pt.ExpiresIn, &unix); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return PendingToken{}, ErrPendingTokenNotFound
		}
		return PendingToken{}, err
	}
	pt.CreatedAt = time.Unix(unix, 0)

	if time.Since(pt.CreatedAt) > ttl {
		_, _ = tx.ExecContext(ctx, `DELETE FROM pending_token WHERE broker_code = ?`, brokerCode)
		if err := tx.Commit(); err != nil {
			return PendingToken{}, err
		}
		return PendingToken{}, ErrPendingTokenNotFound
	}

	if _, err := tx.ExecContext(ctx, `DELETE FROM pending_token WHERE broker_code = ?`, brokerCode); err != nil {
		return PendingToken{}, err
	}
	if err := tx.Commit(); err != nil {
		return PendingToken{}, err
	}
	return pt, nil
}

// PruneExpired deletes rows older than ttl from both auth_state and pending_token.
func (s *S) PruneExpired(ctx context.Context, ttl time.Duration) (int64, error) {
	cutoff := time.Now().Add(-ttl).Unix()
	res1, err := s.db.ExecContext(ctx, `DELETE FROM auth_state WHERE created_at < ?`, cutoff)
	if err != nil {
		return 0, err
	}
	n1, _ := res1.RowsAffected()
	res2, err := s.db.ExecContext(ctx, `DELETE FROM pending_token WHERE created_at < ?`, cutoff)
	if err != nil {
		return n1, err
	}
	n2, _ := res2.RowsAffected()
	return n1 + n2, nil
}

// Ping verifies database connectivity.
func (s *S) Ping(ctx context.Context) error {
	return s.db.PingContext(ctx)
}

// Close closes the database.
func (s *S) Close() error {
	return s.db.Close()
}
