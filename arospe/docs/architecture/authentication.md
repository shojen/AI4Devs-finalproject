# Authentication

Cross-cutting concern — this is the single source of truth for how authentication, two-factor authentication, and passkeys work in this app. Other documents link here instead of re-explaining it.

## Table of Contents

This document is split into parts so an agent reads only the one its task touches. Open the part whose *Read when* column matches; every part carries the original text unchanged, and every heading keeps its original anchor name (only the file changed).

| Part | Read when | Sections |
| --- | --- | --- |
| [Features, registration, account status](authentication/features-registration-and-status.md) | you touch enabled Fortify features, registration/password reset, or the account lifecycle (`users.status`, activation). | Stack; Enabled features; Registration & password reset; Account status and activation |
| [Sign-in block and pending email changes](authentication/sign-in-block-and-email-change.md) | you touch the sign-in account-status block or the pending-email change mechanism. | Sign-in: the account-status block; Pending email changes |
| [Two-factor, passkeys, logout, where it lives](authentication/two-factor-passkeys-logout-and-map.md) | you touch two-factor authentication, passkeys, logout, or need the map of where auth code lives. | Two-factor authentication flow; Passkeys; Logout; Where it lives |

_Last updated: 2026-09-26 — activation and sign-in-safety-net listeners are described as auto-discovered, not hand-registered (story 0064a); the parts above are otherwise as split on 2026-09-24. The prior revision-history footer, if any, stays at the end of the last part._
