# Workbench

The demo app for driving `byrcsc/laravel-assignment` by hand. Build it from
clean with:

```bash
composer build
```

This creates the SQLite database, publishes the package migration the way a
real application installs it, and migrates.

The demo scenarios — leads rotating through sellers, deliveries offered to
drivers with a cascade — land with issue F2 once the core lifecycle exists.
Until then this app is the empty shell that proves the scaffold boots.
