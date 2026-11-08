# Security

<!-- security-audit:start -->

## About this document

Tory is a single-household inventory application that answers where a thing is. It is a PHP application on Laravel and Filament, served by FrankenPHP with a Postgres database. Users sign in with their own password through the panel after an administrator invites them; a REST API uses bearer tokens and an MCP server for Claude uses OAuth. The application ships as a container image built and pushed by GitHub Actions on each release tag.

Checked against OWASP ASVS 5.0 Level 2, curated subset of 93 requirements, plus 12 additional practices.

Last check: 2026-11-08.

## Scope

- own-auth: applied, the panel and the API verify passwords themselves
- rich-text: not applied, no HTML editor and no raw output of user HTML
- outbound-urls: applied, administrators configure the address of a local AI model server
- mail: not applied, the only mail is the verification message to the account's own address
- xml: not applied, no XML parsing
- graphql: not applied, no GraphQL endpoint
- uploads: applied, photo uploads in the panel and the API
- mfa: not applied, no second factor exists
- idp: not applied, no external identity provider
- multi-tenant: not applied, one household per installation
- jwt: applied, OAuth access tokens for the MCP server are signed JWTs
- oauth-client: not applied, the application is only an OAuth server
- webserver-config: applied, an Apache rewrite file is part of the repository
- browser-storage: applied, the theme preference is kept in the browser
- node-backend: not applied, Node builds assets only
- dockerfile: applied, one Dockerfile with development and production targets
- compose: applied, a development Compose stack
- ci: applied, GitHub Actions

This is a self-assessment based on the source code and configuration. It is not a penetration test and makes no claim that the system is free of vulnerabilities.

Requirement text from OWASP ASVS 5.0.0, CC BY-SA 4.0.

## Results

| Chapter | Checked | Implemented | Partial | Not implemented | n/a |
|---|---|---|---|---|---|
| V1 Encoding and Sanitization | 10 | 6 | 0 | 1 | 3 |
| V2 Validation and Business Logic | 3 | 2 | 1 | 0 | 0 |
| V3 Web Frontend Security | 11 | 9 | 1 | 1 | 0 |
| V4 API and Web Service | 2 | 0 | 0 | 0 | 2 |
| V5 File Handling | 5 | 5 | 0 | 0 | 0 |
| V6 Authentication | 14 | 10 | 0 | 1 | 3 |
| V7 Session Management | 8 | 7 | 0 | 1 | 0 |
| V8 Authorization | 4 | 3 | 0 | 0 | 1 |
| V9 Self-contained Tokens | 4 | 3 | 1 | 0 | 0 |
| V10 OAuth and OIDC | 1 | 0 | 0 | 0 | 1 |
| V11 Cryptography | 5 | 5 | 0 | 0 | 0 |
| V12 Secure Communication | 3 | 1 | 1 | 1 | 0 |
| V13 Configuration | 7 | 4 | 3 | 0 | 0 |
| V14 Data Protection | 3 | 2 | 0 | 1 | 0 |
| V15 Secure Coding and Architecture | 8 | 5 | 2 | 0 | 1 |
| V16 Security Logging and Error Handling | 5 | 1 | 2 | 2 | 0 |
| Total | 93 | 63 | 11 | 8 | 11 |

Open and partial items:

- V3.3.1: Verify that cookies have the 'Secure' attribute set
- V1.3.6: Verify that the application protects against Server-side Request Forgery (SSRF) attacks
- V2.4.1: Verify that anti-automation controls are in place to protect against excessive calls to application functions that could lead to data exfiltration
- V3.4.3: Verify that HTTP responses include a Content-Security-Policy response header field which defines directives to ensure the browser only loads and executes trusted content or resources
- V6.3.3: Verify that either a multi-factor authentication mechanism or a combination of single-factor authentication mechanisms
- V7.3.2: Verify that there is an absolute maximum session lifetime such that re-authentication is enforced according to risk analysis and documented security decisions
- V9.2.1: Verify that, if a validity time span is present in the token data, the token and its content are accepted only if the verification time is within this validity time span
- V12.3.1: Verify that an encrypted protocol such as TLS is used for all inbound and outbound connections to and from the application
- V12.3.2: Verify that TLS clients validate certificates received before communicating with a TLS server
- V13.2.2: Verify that communications between backend application components
- V13.2.3: Verify that if a credential has to be used for service authentication
- V13.3.1: Verify that a secrets management solution
- V14.3.2: Verify that the application sets sufficient anti-caching HTTP response header fields
- V15.1.2: Verify that an inventory catalog
- V15.3.5: Verify that the application explicitly ensures that variables are of the correct type and performs strict equality and comparator operations
- V16.3.1: Verify that all authentication operations are logged
- V16.3.2: Verify that failed authorization attempts are logged
- V16.4.1: Verify that all logging components appropriately encode data to prevent log injection
- V16.5.1: Verify that a generic message is returned to the consumer when an unexpected or security-sensitive error occurs

## Additional practices

| ID | Practice | Status | Reference |
|---|---|---|---|
| AP-1 | Dockerfile runs as a non-root user | implemented | CIS Docker Benchmark 4.1 |
| AP-2 | Base images pinned to a version or digest, not `latest` | partial | OpenSSF Scorecard: Pinned-Dependencies |
| AP-3 | No `privileged: true`, no Docker socket mount in Compose | implemented | CIS Docker Benchmark 5.4, 5.31 |
| AP-4 | Database and cache ports not published to the host in Compose | not implemented | OWASP Docker Security Cheat Sheet |
| AP-5 | `.dockerignore` excludes `.git`, `.env`, dependency folders | implemented | OWASP Docker Security Cheat Sheet |
| AP-6 | `.env` and secret files are gitignored; an `.env.example` exists | implemented | OWASP Secrets Management Cheat Sheet |
| AP-7 | No secrets in git history (pattern scan over `git log -p`) | implemented | OWASP Secrets Management Cheat Sheet |
| AP-8 | Dependency update automation configured (Dependabot, Renovate) | partial | OpenSSF Scorecard: Dependency-Update-Tool |
| AP-9 | CI runs a dependency vulnerability audit | not implemented | OpenSSF Scorecard: Vulnerabilities |
| AP-10 | CI runs static analysis (SAST) | not implemented | OpenSSF Scorecard: SAST |
| AP-11 | CI workflow token permissions are minimal (GitHub Actions only) | implemented | OpenSSF Scorecard: Token-Permissions |
| AP-12 | `SECURITY.md` with a contact exists | implemented | OpenSSF Scorecard: Security-Policy |

## Reporting a vulnerability

To report a vulnerability, contact tobias.redmann@gmail.com. Expect a first response within 3 business days.

## Changes

- 2026-11-08 AP-12 not implemented → implemented: this file is now part of the repository.
- 2026-11-08 V12.1.1 not implemented → implemented: the gateway accepts TLS 1.2 and 1.3 and refuses 1.0 and 1.1.
- 2026-11-08 V6.2.5 not implemented → implemented: passwords no longer need specific character classes.
- 2026-11-08 V3.4.6 not implemented → implemented: every response forbids framing by other sites.
- 2026-11-08 V3.4.4 not implemented → implemented: every response carries nosniff.
- 2026-11-08 V3.4.1 not implemented → implemented: every response carries HSTS for two years, including subdomains.
- 2026-11-08 V3.3.1 not implemented → partial: the session cookie is Secure with the __Host- prefix; the CSRF cookie has no prefix.

<!-- security-audit:end -->

## Further evidence

No external assessments yet.
