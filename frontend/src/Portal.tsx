import { useEffect, useState, type FormEvent } from "react";
import { CommercialWorkspace } from "./CommercialWorkspace";
import { portalRequest, PortalRequestError } from "./api";
import "./portal.css";
import { type ActiveAccessDelegation } from "./OrganizationDelegations";
import { OrganizationAccess, OrganizationUsers } from "./OrganizationUsers";
import { OrganizationAccountSetup } from "./OrganizationAccountSetup";
import { BusinessDirectory } from "./BusinessDirectory";
import { MappingWorkspace, type MappingContext } from "./MappingWorkspace";
import { ServiceRegistry } from "./registry";

import { installationStage, type Stage } from "./status";
type Field = [string, string, string];
const fields: Record<Stage, Field[]> = {
  setup_required: [
    ["legal_name", "Legal name", "text"],
    ["professional_name", "Trading name", "text"],
    ["secret", "Setup code", "password"],
  ],
  owner_required: [
    ["name", "Owner name", "text"],
    ["email", "Email address", "email"],
    ["password", "New password (at least 15 characters)", "password"],
    ["secret", "Setup code", "password"],
  ],
  ready: [
    ["code", "User code", "text"],
    ["email", "Email address", "email"],
    ["password", "Password", "password"],
  ],
};
const titles = {
  setup_required: "Set up Main Portal",
  owner_required: "Create owner account",
  ready: "Sign in",
};

export function Portal() {
  const [delegations, setDelegations] = useState<ActiveAccessDelegation[]>([]);
  const [mappingContexts, setMappingContexts] = useState<MappingContext[]>([]);
  const [section, setSection] = useState("services");
  const [setupAccount, setSetupAccount] = useState(false);
  const [stage, setStage] = useState<Stage>();
  const [categories, setCategories] = useState<Record<string, string>>();
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const [theme, setTheme] = useState("system");
  const [layout, setLayout] = useState("compact");
  const [font, setFont] = useState("system-ui");
  async function loadCatalogue() {
    try {
      setCategories(await (await portalRequest("services/categories")).json());
    } catch (error) {
      if (!(error instanceof PortalRequestError) || error.status !== 403)
        throw error;
      const contexts: MappingContext[] = await (
        await portalRequest("mapping-contexts")
      ).json();
      const active: ActiveAccessDelegation[] = await (
        await portalRequest("organization-access-delegations")
      ).json();
      if (!contexts.length && !active.length) throw error;
      setMappingContexts(contexts);
      setDelegations(active);
    }
  }
  useEffect(() => {
    portalRequest("installation")
      .then((r) => r.json())
      .then(async (data) => {
        setStage(installationStage(data.stage));
        if (data.stage === "ready") {
          try {
            await loadCatalogue();
          } catch {
            /* A fresh browser must sign in. */
          }
        }
      })
      .catch(() =>
        setNotice("Installation status unavailable. Reload to retry."),
      );
  }, []);
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!stage || busy) return;
    const values = Object.fromEntries(
      new FormData(event.currentTarget),
    ) as Record<string, string>;
    const { secret, ...body } = values;
    setBusy(true);
    setNotice("");
    try {
      if (stage === "ready") {
        await portalRequest("login", body);
        await loadCatalogue();
      } else {
        const path = stage === "setup_required" ? "setup" : "owner";
        const result = await (
          await portalRequest("installation/" + path, body, secret)
        ).json();
        setStage(installationStage(result.stage));
        if (result.user_code)
          setNotice("Save your user code for sign-in: " + result.user_code);
      }
    } catch (error) {
      setNotice((error as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className="portal" data-theme={theme} style={{ fontFamily: font }}>
      <header>
        <strong>CBM Portal</strong>
        <details>
          <summary>Appearance</summary>
          {(
            [
              ["Theme", theme, setTheme, ["system", "light", "dark"]],
              ["Layout", layout, setLayout, ["compact", "full"]],
              ["Font", font, setFont, ["system-ui", "serif", "monospace"]],
            ] as const
          ).map(([label, value, set, options]) => (
            <label key={label}>
              {label}
              <select value={value} onChange={(e) => set(e.target.value)}>
                {options.map((o) => (
                  <option key={o}>{o}</option>
                ))}
              </select>
            </label>
          ))}
        </details>
      </header>
      <section className={layout}>
        <h1>
          {mappingContexts.length
            ? "Distributor–Shop mappings"
            : delegations.length
              ? "Delegated business access"
              : categories
                ? section === "businesses"
                  ? "Business structure"
                  : section === "users"
                    ? "Organization users"
                    : section === "commercial"
                      ? "Commercial management"
                      : "Service catalogue"
                : stage
                  ? setupAccount
                    ? "Set up account"
                    : titles[stage]
                  : "Portal setup"}
        </h1>
        <p role="status">{notice}</p>
        {categories || mappingContexts.length || delegations.length ? (
          <>
            {delegations.length > 0 && (
              <section aria-label="Active delegations">
                <h2>Active delegations</h2>
                <ul>
                  {delegations.map((grant) => (
                    <li key={grant.id}>
                      {grant.professional_name} · Tenant {grant.tenant_id} ·
                      Business {grant.business_id}
                    </li>
                  ))}
                </ul>
                <OrganizationAccess />
              </section>
            )}
            {mappingContexts.length ? (
              <MappingWorkspace contexts={mappingContexts} />
            ) : (
              categories && (
                <>
                  <nav aria-label="Administration">
                    <button
                      aria-pressed={section === "services"}
                      onClick={() => setSection("services")}
                    >
                      Services
                    </button>
                    <button
                      aria-pressed={section === "businesses"}
                      onClick={() => setSection("businesses")}
                    >
                      Businesses
                    </button>
                    <button
                      aria-pressed={section === "users"}
                      onClick={() => setSection("users")}
                    >
                      Organization users
                    </button>
                    <button
                      aria-pressed={section === "commercial"}
                      onClick={() => setSection("commercial")}
                    >
                      Commercial
                    </button>
                  </nav>
                  {section === "commercial" ? (
                    <CommercialWorkspace />
                  ) : section === "users" ? (
                    <OrganizationUsers />
                  ) : section === "businesses" ? (
                    <BusinessDirectory />
                  ) : (
                    <ServiceRegistry categories={categories} />
                  )}
                </>
              )
            )}
            <button
              onClick={async () => {
                try {
                  await portalRequest("logout", {});
                  setCategories(undefined);
                  setMappingContexts([]);
                  setDelegations([]);
                  setSection("services");
                  setNotice("");
                } catch (e) {
                  setNotice((e as Error).message);
                }
              }}
            >
              Sign out
            </button>
          </>
        ) : (
          stage &&
          (setupAccount ? (
            <>
              <OrganizationAccountSetup
                onDone={() => {
                  setSetupAccount(false);
                  setNotice(
                    "Account setup complete. Sign in with your User Code and new password.",
                  );
                }}
              />
              <button onClick={() => setSetupAccount(false)}>
                Back to sign in
              </button>
            </>
          ) : (
            <>
              <form key={stage} onSubmit={submit}>
                {fields[stage].map(([name, label, type]) => (
                  <label key={name}>
                    {label}
                    <input
                      name={name}
                      type={type}
                      required
                      autoComplete={
                        name === "secret"
                          ? "off"
                          : name === "password"
                            ? stage === "ready"
                              ? "current-password"
                              : "new-password"
                            : undefined
                      }
                    />
                  </label>
                ))}
                <button disabled={busy}>{titles[stage]}</button>
              </form>
              {stage === "ready" && (
                <button
                  onClick={() => {
                    setSetupAccount(true);
                    setNotice("");
                  }}
                >
                  Set up account
                </button>
              )}
            </>
          ))
        )}
      </section>
    </div>
  );
}
