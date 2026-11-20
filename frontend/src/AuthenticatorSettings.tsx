import { useState, type FormEvent } from "react";
import { portalRequest } from "./api";

export function AuthenticatorSettings() {
  const [enabled, setEnabled] = useState<boolean>();
  const [key, setKey] = useState("");
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);

  async function run(operation?: string, form?: HTMLFormElement) {
    setBusy(true);
    setNotice("");
    try {
      const body = form
        ? Object.fromEntries(
            Array.from(new FormData(form), ([name, value]) => [
              name,
              String(value),
            ]),
          )
        : undefined;
      const result = await (
        await portalRequest(
          operation ? "authenticator/" + operation : "authenticator",
          body,
        )
      ).json();
      if (operation === "prepare") {
        setKey(result.setup_key);
      } else {
        setEnabled(result.enabled);
        setKey("");
        if (operation)
          setNotice(
            result.enabled
              ? "Authenticator enabled."
              : "Authenticator disabled.",
          );
      }
      form?.reset();
    } catch (error) {
      setNotice((error as Error).message);
    } finally {
      setBusy(false);
    }
  }

  function submit(event: FormEvent<HTMLFormElement>, operation: string) {
    event.preventDefault();
    return run(operation, event.currentTarget);
  }

  return (
    <details className="authenticator-settings">
      <summary>Authenticator settings</summary>
      {enabled === undefined ? (
        <button disabled={busy} onClick={() => run()}>
          Load authenticator settings
        </button>
      ) : (
        <>
          {key && (
            <p>
              Enter this key in your authenticator app, then enter a current
              code. <code>{key}</code>
            </p>
          )}
          <form
            data-operation="authenticator-settings"
            onSubmit={(event) =>
              submit(event, enabled ? "disable" : key ? "confirm" : "prepare")
            }
          >
            <label>
              Current password
              <input
                name="password"
                type="password"
                autoComplete="current-password"
                required
              />
            </label>
            {(enabled || key) && (
              <label>
                Authenticator code
                <input
                  name="code"
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  pattern="[0-9]{6}"
                  required
                />
              </label>
            )}
            <button disabled={busy}>
              {enabled
                ? "Disable authenticator"
                : key
                  ? "Enable authenticator"
                  : "Get setup key"}
            </button>
          </form>
        </>
      )}
      <p role="status">{notice}</p>
    </details>
  );
}
