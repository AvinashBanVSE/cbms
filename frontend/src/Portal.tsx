import { useEffect, useState, type FormEvent } from "react";
import { CommercialWorkspace } from "./CommercialWorkspace";
import { AuditTrail } from "./AuditTrail";
import { AuthenticatorSettings } from "./AuthenticatorSettings";
import { LegalIdentities } from "./LegalIdentities";
import { portalRequest, PortalRequestError } from "./api";
import "./portal.css";
import { type ActiveAccessDelegation } from "./OrganizationDelegations";
import { OrganizationAccess, OrganizationUsers } from "./OrganizationUsers";
import { OrganizationAccountSetup } from "./OrganizationAccountSetup";
import { AccountRecovery } from "./AccountRecovery";
import { ProviderAccounts } from "./ProviderAccounts";
import { ProviderDirectory } from "./ProviderDirectory";
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

const administration = {
  services: ["Services", "Service catalogue"],
  businesses: ["Businesses", "Business structure"],
  identities: ["Legal identities", "Legal identities"],
  users: ["Organization users", "Organization users"],
  commercial: ["Commercial", "Commercial management"],
  audit: ["Audit Trail", "Audit Trail"],
  provider_users: ["Sub-Portal users", "Sub-Portal user administration"],
} as const;
type AdministrationSection = keyof typeof administration;

interface WorkspaceAccess {
  administration: AdministrationSection[];
  mapping_contexts: MappingContext[];
  delegations: ActiveAccessDelegation[];
  organization_onboarding?: { tenant_id: string; professional_name: string };
  organization_recovery?: { tenant_id: string; professional_name: string };
  provider_directory?: { id: string; professional_name: string };
}

const appearanceOptions = {
  theme: [
    ["system", "System"],
    ["light", "Light"],
    ["dark", "Dark"],
  ],
  layout: [
    ["compact", "Compact"],
    ["full", "Full width"],
  ],
  font: [
    ["system-ui", "System"],
    ["serif", "Serif"],
    ["monospace", "Monospace"],
  ],
  navigation: [
    ["top", "Top bar"],
    ["sidebar", "Sidebar"],
  ],
} as const;
type AppearanceKey = keyof typeof appearanceOptions;
type Appearance = Record<AppearanceKey, string>;
const appearanceKeys = Object.keys(appearanceOptions) as AppearanceKey[];
const appearanceStorageKey = "cbm.appearance";
const defaultAppearance: Appearance = {
  theme: "system",
  layout: "compact",
  font: "system-ui",
  navigation: "top",
};

function loadAppearance(): Appearance {
  try {
    const saved: Partial<Appearance> | null = JSON.parse(
      localStorage.getItem(appearanceStorageKey) ?? "null",
    );
    const appearance = { ...defaultAppearance };
    for (const key of appearanceKeys) {
      const value = saved?.[key];
      if (value && appearanceOptions[key].some(([option]) => option === value))
        appearance[key] = value;
    }
    return appearance;
  } catch {
    return defaultAppearance;
  }
}

export function Portal() {
  const [workspace, setWorkspace] = useState<WorkspaceAccess>();
  const [section, setSection] = useState<AdministrationSection>();
  const [setupAccount, setSetupAccount] = useState(false);
  const [needsAuthenticator, setNeedsAuthenticator] = useState(false);
  const [needsEnrollment, setNeedsEnrollment] = useState(false);
  const [stage, setStage] = useState<Stage>();
  const [categories, setCategories] = useState<Record<string, string>>();
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const [appearance, setAppearance] = useState(loadAppearance);
  const { theme, layout, font, navigation } = appearance;

  function changeAppearance(key: AppearanceKey, value: string) {
    const next = { ...appearance, [key]: value };
    setAppearance(next);
    try {
      localStorage.setItem(appearanceStorageKey, JSON.stringify(next));
    } catch {
      // Keep the selection in memory when browser storage is unavailable.
    }
  }

  async function loadWorkspace() {
    const access: WorkspaceAccess = await (
      await portalRequest("workspace-access")
    ).json();
    setWorkspace(access);
    setSection(access.administration[0]);
    if (access.administration.includes("services")) {
      setCategories(await (await portalRequest("services/categories")).json());
    }
  }

  useEffect(() => {
    portalRequest("installation")
      .then((r) => r.json())
      .then(async (data) => {
        setStage(installationStage(data.stage));
        if (data.stage !== "ready") return;
        try {
          await loadWorkspace();
        } catch (error) {
          if (!(error instanceof PortalRequestError) || error.status !== 401)
            setNotice("Workspace unavailable. Reload to retry.");
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
        const response = await portalRequest("login", body);
        if (response.status === 202) {
          const challenge = await response.json();
          if (challenge.stage === "authenticator_enrollment_required") {
            setNeedsEnrollment(true);
            setNeedsAuthenticator(false);
            setNotice("Your Organization requires authenticator setup.");
            return;
          }
          setNeedsAuthenticator(true);
          setNotice("Enter the current code from your authenticator.");
          return;
        }
        setNeedsAuthenticator(false);
        await loadWorkspace();
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
  const mappingContexts = workspace?.mapping_contexts ?? [];
  const delegations = workspace?.delegations ?? [];
  const title = section
    ? administration[section][1]
    : mappingContexts.length
      ? "Distributor–Shop mappings"
      : delegations.length
        ? "Delegated business access"
        : workspace
          ? "Your workspace"
          : stage
            ? setupAccount
              ? "Set up account"
              : titles[stage]
            : "Portal setup";

  return (
    <div className="portal" data-theme={theme} style={{ fontFamily: font }}>
      <header className="portal-header">
        <strong>CBM Portal</strong>
        <details>
          <summary>Appearance</summary>
          <div className="appearance-options">
            {appearanceKeys.map((key) => (
              <label key={key}>
                {key[0].toUpperCase() + key.slice(1)}
                <select
                  name={key}
                  value={appearance[key]}
                  onChange={(event) =>
                    changeAppearance(key, event.target.value)
                  }
                >
                  {appearanceOptions[key].map(([value, label]) => (
                    <option key={value} value={value}>
                      {label}
                    </option>
                  ))}
                </select>
              </label>
            ))}
          </div>
        </details>
      </header>
      <section className={layout}>
        <h1>{title}</h1>
        <p role="status">{notice}</p>
        {workspace ? (
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
            {mappingContexts.length > 0 && (
              <MappingWorkspace contexts={mappingContexts} />
            )}
            {workspace.provider_directory && (
              <section aria-label="Sub-Portal workspace">
                <h2>
                  {workspace.provider_directory.professional_name} · Served
                  Organizations
                </h2>
                <ProviderDirectory />
              </section>
            )}
            {workspace.organization_recovery && (
              <section aria-label="Organization account recovery">
                <h2>
                  {workspace.organization_recovery.professional_name} · Account
                  recovery
                </h2>
                <AccountRecovery
                  tenantId={workspace.organization_recovery.tenant_id}
                />
              </section>
            )}
            {workspace.organization_onboarding && (
              <section aria-label="Organization onboarding">
                <h2>
                  {workspace.organization_onboarding.professional_name} · User
                  onboarding
                </h2>
                <OrganizationUsers
                  tenantId={workspace.organization_onboarding.tenant_id}
                />
              </section>
            )}
            {section && (
              <div className="workspace" data-navigation={navigation}>
                <nav aria-label="Administration">
                  {workspace.administration.map((item) => (
                    <button
                      key={item}
                      aria-pressed={section === item}
                      onClick={() => setSection(item)}
                    >
                      {administration[item][0]}
                    </button>
                  ))}
                </nav>
                <section
                  className="workspace-content"
                  aria-label="Administration workspace"
                >
                  {section === "commercial" ? (
                    <CommercialWorkspace />
                  ) : section === "audit" ? (
                    <AuditTrail />
                  ) : section === "provider_users" ? (
                    <ProviderAccounts />
                  ) : section === "identities" ? (
                    <LegalIdentities />
                  ) : section === "users" ? (
                    <OrganizationUsers />
                  ) : section === "businesses" ? (
                    <BusinessDirectory />
                  ) : (
                    categories && <ServiceRegistry categories={categories} />
                  )}
                </section>
              </div>
            )}
            {!section &&
              !mappingContexts.length &&
              !delegations.length &&
              !workspace.organization_onboarding &&
              !workspace.organization_recovery &&
              !workspace.provider_directory && (
                <p>No workspace is assigned to this account.</p>
              )}
            <AuthenticatorSettings />
            <button
              onClick={async () => {
                try {
                  await portalRequest("logout", {});
                  setCategories(undefined);
                  setWorkspace(undefined);
                  setSection(undefined);
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
          (needsEnrollment ? (
            <>
              <AuthenticatorSettings
                onEnrollment={() => {
                  setNeedsEnrollment(false);
                  setNotice(
                    "Authenticator enabled. Sign in with a new code from your app.",
                  );
                }}
              />
              <button onClick={() => setNeedsEnrollment(false)}>
                Back to sign in
              </button>
            </>
          ) : setupAccount ? (
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
                {stage === "ready" && needsAuthenticator && (
                  <label>
                    Authenticator code
                    <input
                      name="authenticator_code"
                      inputMode="numeric"
                      autoComplete="one-time-code"
                      pattern="[0-9]{6}"
                      required
                    />
                  </label>
                )}
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
