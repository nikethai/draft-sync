package main

import (
	"context"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"draftsync-bridge/internal/config"
	"draftsync-bridge/internal/httpapi"
	"draftsync-bridge/internal/oauth"
	"draftsync-bridge/internal/store"
)

func main() {
	ctx, cancel := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer cancel()

	logger := slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: slog.LevelInfo}))

	// Load and validate configuration.
	cfg, err := config.Load()
	if err != nil {
		logger.Error("invalid configuration", "err", err)
		os.Exit(1)
	}

	if cfg.IsAllowlistEmpty() {
		logger.Warn("BRIDGE_REDIRECT_ALLOWLIST is empty — dev mode (BRIDGE_ALLOW_ANY_REDIRECT_FOR_DEV=1); all redirect_uri hosts are allowed")
	}

	// Open the SQLite store.
	db, err := store.Open(cfg.DBPath)
	if err != nil {
		logger.Error("open store", "err", err)
		os.Exit(1)
	}
	defer func() {
		if err := db.Close(); err != nil {
			logger.Error("close store", "err", err)
		}
	}()

	// Background prune of expired state rows.
	go func() {
		ticker := time.NewTicker(cfg.StateTTL)
		defer ticker.Stop()
		for {
			select {
			case <-ctx.Done():
				return
			case <-ticker.C:
				n, err := db.PruneExpired(context.Background(), cfg.StateTTL)
				if err != nil {
					logger.Error("prune expired states", "err", err)
				} else if n > 0 {
					logger.Info("pruned expired states", "count", n)
				}
			}
		}
	}()

	// Build Google OAuth client.
	google := oauth.NewGoogle(cfg.GoogleClientID, cfg.GoogleClientSecret, cfg.GoogleCallback())

	// Build HTTP server.
	handlers := &httpapi.Handlers{
		Store:  db,
		Google: google,
		Config: cfg,
		Log:    logger,
	}
	router := httpapi.NewRouter(handlers, logger)

	server := &http.Server{
		Addr:              cfg.ListenAddr,
		Handler:           router,
		ReadHeaderTimeout: 10 * time.Second,
	}

	// Start server.
	go func() {
		logger.Info("listening", "addr", cfg.ListenAddr)
		if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			logger.Error("server error", "err", err)
			os.Exit(1)
		}
	}()

	// Wait for shutdown signal.
	<-ctx.Done()
	logger.Info("shutting down")

	shutdownCtx, shutdownCancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer shutdownCancel()

	if err := server.Shutdown(shutdownCtx); err != nil {
		logger.Error("graceful shutdown", "err", err)
	}
	fmt.Println("bridge stopped")
}
