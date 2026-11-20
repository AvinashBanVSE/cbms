import { useState, type FormEvent } from "react";
import { OrganizationDelegations } from "./OrganizationDelegations";
import { AccountRecovery } from "./AccountRecovery";
import { OrganizationAccountLifecycle } from "./OrganizationAccountLifecycle";
import { OrganizationRoles } from "./OrganizationRoles";
import { portalRequest } from "./api";

const methods: Record<string, string> = {
  temporary_password: "Email temporary password",
  otp: "Email one-time code",
  both: "Both required",
};

const policySources: Record<string, string> = {
  organization: "Organization",
  sub_portal: "Sub-Portal",
  main_portal: "Main Portal",
};

const actions = [
  ["mapping.initiate", "Generate Shop code"],
  ["mapping.confirm", "Confirm Distributor code"],
  ["mapping.verify", "Verify Shop code"],
  ["mapping.end", "End mapping"],
  ["mapping.history.view", "View Mapping History"],
];

interface BusinessAccess {
  associated: boolean;
  in_scope: boolean;
  actions: string[];
}

export function OrganizationAccess() {
  const [selection, setSelection] = useState<{
    path: string;
    code: string;
    business: string;
    access: BusinessAccess;
  }>();
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);

  async function load(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    const [tenant, code, business] = ["tenant", "code", "business"].map(
      (name) => String(data.get(name)).trim(),
    );
    const path = [
      "tenants",
      tenant,
      "organization-users",
      code,
      "business-access",
      business,
    ]
      .map(encodeURIComponent)
      .join("/");
    setSelection(undefined);
    setNotice("");
    setBusy(true);
    try {
      setSelection({
        path,
        code,
        business,
        access: await (await portalRequest(path)).json(),
      });
    } catch (error) {
      setNotice((error as Error).message);
    } finally {
      setBusy(false);
    }
  }

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!selection) return;
    const data = new FormData(event.currentTarget);
    setNotice("");
    setBusy(true);
    try {
      const access = await (
        await portalRequest(selection.path, {
          associated: data.has("associated"),
          in_scope: data.has("in_scope"),
          actions: data.getAll("actions").map(String),
        })
      ).json();
      setSelection({ ...selection, access });
      setNotice("Business access updated.");
    } catch (error) {
      setNotice((error as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <details>
      <summary>Manage business access</summary>
      <p role="status">{notice}</p>
      <form data-operation="access-lookup" onSubmit={load}>
        <label>
          Organization Tenant ID
          <input name="tenant" required />
        </label>
        <label>
          User Code
          <input name="code" pattern="[A-Z]{2}[0-9]{4}" required />
        </label>
        <label>
          Shop or Distributor ID
          <input name="business" required />
        </label>
        <button disabled={busy}>Load access</button>
      </form>
      {selection && (
        <form
          data-operation="access-update"
          key={selection.path}
          onSubmit={save}
        >
          <h3>
            {selection.code} · {selection.business}
          </h3>
          <label>
            <input
              name="associated"
              type="checkbox"
              defaultChecked={selection.access.associated}
            />
            Active business association
          </label>
          <label>
            <input
              name="in_scope"
              type="checkbox"
              defaultChecked={selection.access.in_scope}
            />
            Include in assigned scope
          </label>
          <fieldset>
            <legend>Allowed mapping actions</legend>
            {actions.map(([value, label]) => (
              <label key={value}>
                <input
                  name="actions"
                  type="checkbox"
                  value={value}
                  defaultChecked={selection.access.actions.includes(value)}
                />
                {label}
              </label>
            ))}
          </fieldset>
          <button disabled={busy}>Save business access</button>
        </form>
      )}
    </details>
  );
}

export function OrganizationUsers({ tenantId }: { tenantId?: string } = {}) {
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const operation = form.dataset.operation;
    const fields = new FormData(form);
    const tenant = encodeURIComponent(
      tenantId ?? String(fields.get("tenant")).trim(),
    );
    const base = "tenants/" + tenant;
    const policy = operation === "policy";
    const lookup = operation === "lookup";
    const provider = operation === "provider";
    const reissue = operation === "reissue";
    const revoke = operation === "revoke-onboarding";
    setBusy(true);
    setNotice("");
    try {
      const endpoint = provider
        ? "providers/" +
          encodeURIComponent(String(fields.get("provider")).trim()) +
          "/credential-default"
        : base +
          (policy || lookup
            ? "/credential-policy"
            : reissue
              ? "/organization-users/reissue"
              : revoke
                ? "/organization-users/revoke-onboarding"
                : "/organization-users");
      const body: Record<string, string | string[]> | undefined = lookup
        ? undefined
        : policy || provider
          ? { mode: String(fields.get("mode")) }
          : reissue || revoke
            ? {
                code: String(fields.get("code")),
                ...(revoke && fields.get("permission")
                  ? { permission: String(fields.get("permission")) }
                  : {}),
              }
            : {
                email: String(fields.get("email")),
                business_id: String(fields.get("business_id")),
                actions: fields.getAll("actions").map(String),
              };
      const result = await (await portalRequest(endpoint, body)).json();
      if (provider) {
        setNotice("Service provider default saved.");
      } else if (revoke) {
        setNotice(
          fields.get("permission")
            ? "Organization recovery permission revoked."
            : "Organization onboarding permission revoked.",
        );
      } else if (policy || lookup) {
        const effective =
          result.mode && result.source
            ? methods[result.mode] + " · " + policySources[result.source]
            : "No effective setup policy.";
        setNotice(
          (policy ? "Credential setup policy saved. " : "") + effective,
        );
      } else {
        const delivery =
          result.delivery === "sent"
            ? "Setup credentials delivered."
            : "Email could not be sent. Reissue the setup credentials.";
        setNotice(
          result.user_code
            ? "User Code: " + result.user_code + ". " + delivery
            : delivery,
        );
      }
    } catch (error) {
      setNotice((error as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <p role="status">{notice}</p>
      {!tenantId && (
        <details>
          <summary>Service provider credential default</summary>
          <form data-operation="provider" onSubmit={submit}>
            <label>
              Main Portal or Sub-Portal ID
              <input name="provider" required />
            </label>
            <label>
              Default setup method
              <select name="mode" required>
                {Object.entries(methods).map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </select>
            </label>
            <button disabled={busy}>Save provider default</button>
          </form>
        </details>
      )}
      <details>
        <summary>Check effective Organization policy</summary>
        <form data-operation="lookup" onSubmit={submit}>
          {!tenantId && (
            <label>
              Organization Tenant ID
              <input name="tenant" required />
            </label>
          )}
          <button disabled={busy}>Check policy</button>
        </form>
      </details>
      {!tenantId && (
        <details>
          <summary>Credential setup policy</summary>
          <form data-operation="policy" onSubmit={submit}>
            <label>
              Organization Tenant ID
              <input name="tenant" required />
            </label>
            <label>
              Setup method
              <select name="mode" required>
                <option value="inherit">Inherit service provider policy</option>
                {Object.entries(methods).map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </select>
            </label>
            <button disabled={busy}>Save policy</button>
          </form>
        </details>
      )}
      <details>
        <summary>Create authorized user</summary>
        <form onSubmit={submit}>
          {!tenantId && (
            <label>
              Organization Tenant ID
              <input name="tenant" required />
            </label>
          )}
          <label>
            User email
            <input name="email" type="email" required />
          </label>
          <label>
            {tenantId
              ? "Shop or Distributor ID"
              : "Organization, Shop or Distributor ID"}
            <input name="business_id" required />
          </label>
          <fieldset>
            <legend>Allowed actions</legend>
            {actions.map(([value, label]) => (
              <label key={value}>
                <input name="actions" type="checkbox" value={value} />
                {label}
              </label>
            ))}
            {!tenantId && (
              <>
                <label>
                  <input
                    name="actions"
                    type="checkbox"
                    value="organization_users.provision"
                  />
                  Provision users in this Organization
                </label>
                <label>
                  <input
                    name="actions"
                    type="checkbox"
                    value="organization_users.recover"
                  />
                  Recover staff accounts in this Organization
                </label>
              </>
            )}
          </fieldset>
          <button disabled={busy}>Create user</button>
        </form>
      </details>
      <details>
        <summary>Reissue setup credentials</summary>
        <form data-operation="reissue" onSubmit={submit}>
          {!tenantId && (
            <label>
              Organization Tenant ID
              <input name="tenant" required />
            </label>
          )}
          <label>
            User Code
            <input name="code" pattern="[A-Z]{2}[0-9]{4}" required />
          </label>
          <button disabled={busy}>Reissue credentials</button>
        </form>
      </details>
      {!tenantId && (
        <>
          <details>
            <summary>
              Revoke Organization account administration permission
            </summary>
            <form data-operation="revoke-onboarding" onSubmit={submit}>
              <label>
                Organization Tenant ID
                <input name="tenant" required />
              </label>
              <label>
                User Code
                <input name="code" pattern="[A-Z]{2}[0-9]{4}" required />
              </label>
              <label>
                Permission
                <select name="permission">
                  <option value="">Onboarding</option>
                  <option value="organization_users.recover">
                    Account recovery
                  </option>
                </select>
              </label>
              <button disabled={busy}>Revoke permission</button>
            </form>
          </details>
          <AccountRecovery />
          <OrganizationAccess />
          <OrganizationAccountLifecycle />
          <OrganizationDelegations />
          <OrganizationRoles actions={actions} />
        </>
      )}
    </>
  );
}
