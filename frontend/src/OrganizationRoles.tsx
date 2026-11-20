import { useState, type FormEvent } from "react";
import { RoleApprovals } from "./RoleApprovals";
import { portalRequest } from "./api";

interface Role {
  id: string;
  name: string;
  actions: string[];
}

interface RolePage {
  data: Role[];
  next_page_url: string | null;
}

export function OrganizationRoles({ actions }: { actions: string[][] }) {
  const [directory, setDirectory] = useState<{
    base: string;
    page: number;
    roles: RolePage;
  }>();
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);

  async function refresh(base: string, page: number) {
    const roles = (await (
      await portalRequest(base + "?page=" + page)
    ).json()) as RolePage;
    setDirectory({ base, page, roles });
  }

  async function load(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    const tenant = encodeURIComponent(String(data.get("tenant")).trim());
    const business = encodeURIComponent(String(data.get("business")).trim());
    setDirectory(undefined);
    await run(() =>
      refresh("tenants/" + tenant + "/businesses/" + business + "/roles", 1),
    );
  }

  async function run(operation: () => Promise<void>) {
    setBusy(true);
    setNotice("");
    try {
      await operation();
    } catch (error) {
      setNotice((error as Error).message);
    } finally {
      setBusy(false);
    }
  }

  async function change(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!directory) return;
    const form = event.currentTarget;
    const fields = new FormData(form);
    await run(async () => {
      if (form.dataset.operation === "role-create") {
        await portalRequest(directory.base, {
          name: String(fields.get("name")),
          actions: fields.getAll("actions").map(String),
        });
        await refresh(directory.base, 1);
        setNotice("Role created.");
      } else {
        const submitter = (event.nativeEvent as SubmitEvent)
          .submitter as HTMLButtonElement | null;
        const revoke = submitter?.value === "revoke";
        const response = await portalRequest(
          directory.base +
            "/" +
            encodeURIComponent(String(fields.get("role"))) +
            (revoke ? "/revoke" : "/assign"),
          {
            code: String(fields.get("code")),
          },
        );
        if (response.status === 202) {
          const pending = await response.json();
          setNotice("Awaiting approval: " + pending.id);
        } else {
          setNotice(revoke ? "Role removed." : "Role assigned.");
        }
      }
      form.reset();
    });
  }

  return (
    <details>
      <summary>Manage business roles</summary>
      <p role="status">{notice}</p>
      <form data-operation="role-lookup" onSubmit={load}>
        <label>
          Organization Tenant ID
          <input name="tenant" required />
        </label>
        <label>
          Shop or Distributor ID
          <input name="business" required />
        </label>
        <button disabled={busy}>Load roles</button>
      </form>
      {directory && (
        <>
          <ul aria-label="Roles on this page">
            {directory.roles.data.map((role) => (
              <li key={role.id}>
                {role.name} · {role.actions.join(", ")}
              </li>
            ))}
          </ul>
          {directory.page > 1 && (
            <button
              disabled={busy}
              onClick={() =>
                run(() => refresh(directory.base, directory.page - 1))
              }
            >
              Previous roles
            </button>
          )}
          {directory.roles.next_page_url && (
            <button
              disabled={busy}
              onClick={() =>
                run(() => refresh(directory.base, directory.page + 1))
              }
            >
              Next roles
            </button>
          )}
          <form data-operation="role-create" onSubmit={change}>
            <label>
              Role name
              <input name="name" maxLength={100} required />
            </label>
            <fieldset>
              <legend>Mapping actions</legend>
              {actions.map(([value, label]) => (
                <label key={value}>
                  <input type="checkbox" name="actions" value={value} />
                  {label}
                </label>
              ))}
            </fieldset>
            <button disabled={busy}>Create role</button>
          </form>
          {directory.roles.data.length > 0 && (
            <form data-operation="role-assignment" onSubmit={change}>
              <label>
                Role
                <select name="role" required>
                  {directory.roles.data.map((role) => (
                    <option key={role.id} value={role.id}>
                      {role.name}
                    </option>
                  ))}
                </select>
              </label>
              <label>
                User Code
                <input name="code" pattern="[A-Z]{2}[0-9]{4}" required />
              </label>
              <button disabled={busy} value="assign">
                Assign role
              </button>
              <button disabled={busy} value="revoke">
                Remove role
              </button>
            </form>
          )}
          <RoleApprovals
            key={directory.base}
            base={directory.base.replace(/\/roles$/, "/role-approvals")}
          />
        </>
      )}
    </details>
  );
}
