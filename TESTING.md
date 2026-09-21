# Testing

## Native wp-admin / Jurassic Tube demo

Follow [the native demo testing guide](experiments/native-admin/TESTING.md). It covers startup, the authenticated protocol suite, asset URL checks, browser harness, actual chat acceptance, reversible save validation and cleanup. The final user-confirmed rendering outcome and limitations are recorded in [LEARNINGS.md](experiments/native-admin/LEARNINGS.md).

```sh
python3 experiments/native-admin/test-protocol.py
```

This requires the running proof endpoint and Keychain credentials. It is an HTTP regression suite, not proof of interactive inline rendering.

## Main fragment viewer

```sh
npm run build
npm run test:rendering
```

The smoke test requires the main plugin endpoint `/wp-json/wp-ai-fragments/v1/mcp`, its seeded product 12 and application password. It verifies resource/tool wiring and submits the current content value through the main viewer's update tool. It is separate from the native iframe experiment. If the proof's plugin entrypoint is active instead, the main route may return 404; select the intended profile before running it.

## Before reporting success

- Check the actual record type/ID after every fresh Playground start; startup rebuilds the database.
- Distinguish protocol, direct browser, browser harness and actual chat results.
- Check scripts/styles and native tab interactions, not merely HTTP 200 or a completed handshake.
- Verify a reversible persisted save before claiming end-to-end editing works.
- Never include credentials, cookies, grants, private keys or authenticated page dumps in test evidence or commits.
