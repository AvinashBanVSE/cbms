import { useState, type FormEvent } from "react";
import { CommercialBilling } from "./CommercialBilling";
import { portalRequest } from "./api";

type Field = {
  name: string;
  label: string;
  type?: string;
  options?: [string, string][];
  optional?: boolean;
};
type Section =
  | "accounts"
  | "entitlements"
  | "plans"
  | "versions"
  | "subscriptions";
const sections: Record<Section, { label: string; fields: Field[] }> = {
  accounts: {
    label: "Commercial accounts",
    fields: [
      { name: "tenant_id", label: "Organization Tenant ID" },
      { name: "provider_id", label: "Service provider ID" },
      { name: "billing_party", label: "Contracting and billing party" },
    ],
  },
  entitlements: {
    label: "Feature entitlements",
    fields: [
      { name: "code", label: "Entitlement Code" },
      { name: "name", label: "Feature name" },
    ],
  },
  plans: {
    label: "Plans",
    fields: [
      { name: "provider_id", label: "Service provider ID" },
      { name: "name", label: "Plan name" },
      {
        name: "family",
        label: "Plan family",
        options: [
          ["operator", "Operator"],
          ["tenant", "Tenant"],
          ["dealer", "Dealer"],
        ],
      },
    ],
  },
  versions: {
    label: "Published Plan Versions",
    fields: [
      { name: "plan_id", label: "Plan ID" },
      {
        name: "amount_paise",
        label: "Term price in paise (INR)",
        type: "number",
      },
      { name: "term_days", label: "Term in calendar days", type: "number" },
      {
        name: "entitlements",
        label: "Feature entitlement codes (comma separated)",
        optional: true,
      },
    ],
  },
  subscriptions: {
    label: "Subscriptions",
    fields: [
      { name: "account_id", label: "Commercial Account ID" },
      { name: "plan_version_id", label: "Tenant Plan Version ID" },
      { name: "starts_on", label: "Starts on (India calendar)", type: "date" },
      {
        name: "coverage_mode",
        label: "Coverage",
        options: [
          ["organization", "Organization only"],
          ["selected", "Selected businesses"],
          ["network", "Current Organization network"],
          ["future_network", "Current and future Organization network"],
        ],
      },
      {
        name: "business_ids",
        label: "Business IDs for selected coverage (comma separated)",
        optional: true,
      },
    ],
  },
};

interface Listing {
  data: Record<string, unknown>[];
  next_page_url: string | null;
}

interface AvailableFeatures {
  business_id: string;
  feature_codes: string[];
}

const subscriptionActions: Record<string, [string, string][]> = {
  "Pending Activation": [
    ["activate", "Activate"],
    ["cancel", "Cancel immediately"],
  ],
  Active: [
    ["suspend", "Suspend"],
    ["cancel", "Cancel immediately"],
  ],
  "Grace Period": [
    ["activate", "Activate"],
    ["suspend", "Suspend"],
    ["cancel", "Cancel immediately"],
  ],
  Suspended: [
    ["activate", "Reinstate"],
    ["cancel", "Cancel immediately"],
  ],
};

export function CommercialWorkspace() {
  const [section, setSection] = useState<Section>("accounts");
  const [listing, setListing] = useState<Listing>();
  const [page, setPage] = useState(1);
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const [features, setFeatures] = useState<AvailableFeatures>();
  const [renewalMode, setRenewalMode] = useState("manual");
  const [gracePolicy, setGracePolicy] = useState("");

  async function run(fn: () => Promise<void>) {
    setBusy(true);
    setNotice("");
    try {
      await fn();
    } catch (error) {
      setNotice((error as Error).message);
    } finally {
      setBusy(false);
    }
  }

  async function load(number: number) {
    setListing(
      await (
        await portalRequest("commercial?section=" + section + "&page=" + number)
      ).json(),
    );
    setPage(number);
  }

  function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const fields = new FormData(form);
    const body: Record<string, string | string[]> = Object.fromEntries(
      Array.from(fields.entries(), ([name, value]) => [
        name,
        String(value).trim(),
      ]),
    );
    const plan = String(body.plan_id ?? "");
    const split = (value: unknown) =>
      String(value ?? "")
        .split(",")
        .map((entry) => entry.trim())
        .filter(Boolean);
    if (section === "versions") {
      delete body.plan_id;
      body.entitlements = split(body.entitlements);
    }
    if (section === "subscriptions") {
      body.renewal_mode = renewalMode;
      body.business_ids = split(body.business_ids);
      if (body.coverage_mode !== "selected" && body.business_ids.length === 0)
        delete body.business_ids;
    }
    return run(async () => {
      const path =
        section === "versions"
          ? "commercial/plans/" + encodeURIComponent(plan) + "/versions"
          : "commercial/" + section;
      const result = await (await portalRequest(path, body)).json();
      await load(1);
      setNotice(
        (result.state ? result.state + " · " : "") +
          "Saved: " +
          (result.id ?? result.code),
      );
      form.reset();
      setRenewalMode("manual");
      setGracePolicy("");
    });
  }

  function transition(
    event: FormEvent<HTMLFormElement>,
    id: string,
    state: string,
  ) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    return run(async () => {
      await portalRequest(
        "commercial/subscriptions/" + encodeURIComponent(id) + "/transition",
        {
          action: String(data.get("action")),
          expected_state: state,
          reason: String(data.get("reason")).trim(),
        },
      );
      setFeatures(undefined);
      form.reset();
      await load(page);
      setNotice("Subscription state updated.");
    });
  }

  function lookupFeatures(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const business = String(
      new FormData(event.currentTarget).get("business_id"),
    ).trim();
    setFeatures(undefined);
    return run(async () => {
      setFeatures(
        await (
          await portalRequest(
            "commercial/businesses/" +
              encodeURIComponent(business) +
              "/effective-entitlements",
          )
        ).json(),
      );
    });
  }

  function renew(event: FormEvent<HTMLFormElement>, id: string) {
    event.preventDefault();
    const form = event.currentTarget;
    const fields = new FormData(form);
    return run(async () => {
      const result = await (
        await portalRequest(
          "commercial/subscriptions/" + encodeURIComponent(id) + "/renew",
          {
            plan_version_id: String(fields.get("plan_version_id")).trim(),
            reason: String(fields.get("reason")).trim(),
          },
        )
      ).json();
      form.reset();
      await load(page);
      setNotice(
        "Renewal saved: " + result.id + " · Starts " + result.starts_on,
      );
    });
  }

  return (
    <>
      <label>
        Commercial section
        <select
          value={section}
          disabled={busy}
          onChange={(event) => {
            setSection(event.target.value as Section);
            setListing(undefined);
            setPage(1);
            setNotice("");
            setFeatures(undefined);
            setRenewalMode("manual");
            setGracePolicy("");
          }}
        >
          {Object.entries(sections).map(([key, value]) => (
            <option key={key} value={key}>
              {value.label}
            </option>
          ))}
        </select>
      </label>
      <form key={section} data-operation="commercial-create" onSubmit={save}>
        {sections[section].fields.map((field) => (
          <label key={field.name}>
            {field.label}
            {field.options ? (
              <select name={field.name}>
                {field.options.map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </select>
            ) : (
              <input
                name={field.name}
                type={field.type ?? "text"}
                required={!field.optional}
              />
            )}
          </label>
        ))}
        {section === "subscriptions" && (
          <>
            <label>
              Renewal method
              <select
                name="renewal_mode"
                value={renewalMode}
                onChange={(event) => {
                  setRenewalMode(event.target.value);
                  setGracePolicy("");
                }}
              >
                <option value="manual">Manual</option>
                <option value="automatic">Automatic</option>
              </select>
            </label>
            {renewalMode === "automatic" && (
              <>
                <label>
                  Days before the next term to issue its invoice
                  <input
                    name="renewal_lead_days"
                    type="number"
                    min="1"
                    max="3660"
                    required
                  />
                </label>
                <label>
                  Renewal grace policy
                  <select
                    data-control="renewal-grace-policy"
                    value={gracePolicy}
                    onChange={(event) => setGracePolicy(event.target.value)}
                  >
                    <option value="">No grace period</option>
                    <option value="retain_features">
                      Keep features available
                    </option>
                    <option value="block_features">Block features</option>
                  </select>
                </label>
                {gracePolicy && (
                  <label>
                    Grace period in days
                    <input
                      name="renewal_grace_days"
                      type="number"
                      min="1"
                      max="3660"
                      required
                    />
                    <input
                      name="renewal_grace_policy"
                      type="hidden"
                      value={gracePolicy}
                    />
                  </label>
                )}
              </>
            )}
            <p>
              The first term requires activation. Automatic renewals start once
              the next term begins and its invoice is settled.
            </p>
          </>
        )}
        <button disabled={busy}>
          {section === "versions" ? "Publish Plan Version" : "Create record"}
        </button>
      </form>
      <button disabled={busy} onClick={() => run(() => load(1))}>
        Load commercial records
      </button>
      {listing && (
        <>
          <ul aria-label="Commercial records">
            {listing.data.map((item) => {
              const state = String(item.state);
              const actions =
                section === "subscriptions"
                  ? subscriptionActions[String(item.effective_state)]
                  : undefined;
              return (
                <li key={String(item.id ?? item.code)}>
                  <dl>
                    {Object.entries(item).map(([name, value]) => (
                      <div key={name}>
                        <dt>{name.replaceAll("_", " ")}</dt>
                        <dd>
                          {typeof value === "object"
                            ? JSON.stringify(value)
                            : String(value)}
                        </dd>
                      </div>
                    ))}
                  </dl>
                  {actions && (
                    <form
                      key={state}
                      data-operation="subscription-transition"
                      onSubmit={(event) =>
                        transition(event, String(item.id), state)
                      }
                    >
                      <label>
                        Subscription action
                        <select name="action" required>
                          <option value="">Choose an action</option>
                          {actions.map(([value, label]) => (
                            <option key={value} value={value}>
                              {label}
                            </option>
                          ))}
                        </select>
                      </label>
                      <label>
                        Reason for the change
                        <input name="reason" required maxLength={500} />
                      </label>
                      <button disabled={busy}>Apply subscription action</button>
                    </form>
                  )}
                  {section === "subscriptions" &&
                    state === "Active" &&
                    !item.renewal_id && (
                      <details>
                        <summary>Renew for the next term</summary>
                        <p>
                          Starts {String(item.ends_before)}. This creates a
                          manual renewal with its own invoice.
                        </p>
                        <form
                          data-operation="subscription-renew"
                          onSubmit={(event) => renew(event, String(item.id))}
                        >
                          <label>
                            Next term Plan Version ID
                            <input
                              name="plan_version_id"
                              defaultValue={String(item.plan_version_id)}
                              required
                            />
                          </label>
                          <label>
                            Renewal reason
                            <input name="reason" required maxLength={500} />
                          </label>
                          <button disabled={busy}>Create next term</button>
                        </form>
                      </details>
                    )}
                </li>
              );
            })}
          </ul>
          {page > 1 && (
            <button disabled={busy} onClick={() => run(() => load(page - 1))}>
              Previous records
            </button>
          )}
          {listing.next_page_url && (
            <button disabled={busy} onClick={() => run(() => load(page + 1))}>
              Next records
            </button>
          )}
        </>
      )}
      {section === "subscriptions" && (
        <>
          <form
            data-operation="effective-entitlements"
            onSubmit={lookupFeatures}
          >
            <label>
              Covered Business ID
              <input name="business_id" required />
            </label>
            <button disabled={busy}>Check available features</button>
          </form>
          {features && (
            <section aria-label="Available features">
              <h3>Available features for {features.business_id}</h3>
              {features.feature_codes.length ? (
                <ul>
                  {features.feature_codes.map((code) => (
                    <li key={code}>{code}</li>
                  ))}
                </ul>
              ) : (
                <p>No features are currently available.</p>
              )}
            </section>
          )}
        </>
      )}
      <p role="status">{notice}</p>
      <CommercialBilling />
    </>
  );
}
