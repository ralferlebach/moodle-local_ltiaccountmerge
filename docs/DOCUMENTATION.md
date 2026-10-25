# Documentation

Keep the documents that outlive a single change here.

* `prompt-templates/` — the prompts used to open and close a working session. The session-end
  prompt is the useful one: it forces a written record before the session is over, when the
  reasoning is still available.
* `sessions/` — one file per session, never overwritten. What changed, which API contracts were
  used, what was tested, and what remains open. The value shows up months later, when nobody
  remembers why something was decided.

Suggested further documents, depending on the project: a requirements document, a technical
blueprint describing boundaries and public contracts, and a backlog.
