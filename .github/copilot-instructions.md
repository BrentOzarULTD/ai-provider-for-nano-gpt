# ModelTrestle review instructions

Review this repository as a WordPress 7.0+ AI provider that must remain compatible
with PHP 7.4 and the WordPress PHP AI Client.

Prioritize findings that could cause incorrect model routing, API incompatibility,
credential exposure, unsafe WordPress administration behavior, or broken release
packages. In particular:

- Require PHP 7.4-compatible syntax and behavior.
- Check capability gates, nonces, permissions, sanitization, and late escaping.
- Never allow WordPress AI Client credentials to be read directly from connector
  options or written to logs, responses, fixtures, artifacts, or source control.
- Treat prompts, generated responses, image data, and account balances as sensitive.
- Verify Nano-GPT text, vision, image, subscription, schema-JSON, model-catalog,
  balance, and error-response behavior when relevant to a change.
- Preserve exact Nano-GPT image size values while normalizing aspect ratios and
  orientations for WordPress.
- Verify preferred-model behavior does not silently fall back to a different model.
- Keep the plugin slug, text domain, bootstrap filename, readme, release workflow,
  and packaged ZIP folder consistent.
- Flag live API calls in pull-request workflows; repository secrets must be limited
  to protected scheduled or manually dispatched workflows.

Avoid comments that only restate the diff or express stylistic preference already
enforced by PHPCS. Focus on actionable correctness, security, compatibility, tests,
and release risks.
