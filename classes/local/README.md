# Business logic

Put the plugin's own logic here, not in entry-point scripts. An entry point resolves parameters,
checks the capability for exactly the action it performs, calls one service method and renders the
result — nothing else. That keeps it testable and keeps a state-changing action in one place.

Cross-plugin access goes through the other plugin's public API, never straight into its tables.
