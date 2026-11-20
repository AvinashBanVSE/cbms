// @vitest-environment jsdom
import { act } from "react";
import { createRoot, type Root } from "react-dom/client";
import { afterEach, beforeEach, expect, it, vi } from "vitest";
import { Portal } from "../src/Portal";
import { portalRequest, PortalRequestError } from "../src/api";

vi.mock("../src/api", async (original) => ({
  ...(await original<typeof import("../src/api")>()),
  portalRequest: vi.fn(),
}));
const request = vi.mocked(portalRequest);
let root: Root;
beforeEach(() => {
  vi.stubGlobal("IS_REACT_ACT_ENVIRONMENT", true);
  localStorage.clear();
  document.body.innerHTML = '<div id="root"></div>';
  root = createRoot(document.querySelector("#root")!);
  request.mockReset();
});
afterEach(async () => {
  await act(async () => root.unmount());
  vi.unstubAllGlobals();
});

async function render() {
  await act(async () => root.render(<Portal />));
}
function input(name: string, value: string) {
  document.querySelector<HTMLInputElement>(`[name="${name}"]`)!.value = value;
}
async function submit(selector = "form") {
  await act(async () => {
    document
      .querySelector(selector)!
      .dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
  });
}
async function click(text: string) {
  const button = [...document.querySelectorAll("button")].find(
    (b) => b.textContent === text,
  );
  expect(button).toBeDefined();
  await act(async () => button!.click());
}
const json = (data: unknown) => Response.json(data);
const adminWorkspace = {
  administration: ["services", "businesses", "users", "commercial"],
  mapping_contexts: [],
  delegations: [],
};

it("keeps dashboard appearance preferences after a reload", async () => {
  request.mockImplementation(async (path) => {
    if (path === "installation") return json({ stage: "ready" });
    if (path === "workspace-access") return json(adminWorkspace);
    if (path === "services/categories") return json({ A1: "Identity" });
    if (path === "services?page=1") return json({ data: [] });
    throw new Error(path);
  });
  localStorage.setItem("cbm.appearance", '{"theme":"unknown","font":"serif"}');
  await render();
  const portal = () => document.querySelector<HTMLDivElement>(".portal")!;
  expect(portal().dataset.theme).toBe("system");
  expect(portal().style.fontFamily).toBe("serif");
  const choose = (name: string, value: string) =>
    act(async () => {
      const control = document.querySelector<HTMLSelectElement>(
        `header select[name="${name}"]`,
      )!;
      control.value = value;
      control.dispatchEvent(new Event("change", { bubbles: true }));
    });
  await choose("theme", "dark");
  await choose("font", "monospace");
  await choose("layout", "full");
  await choose("navigation", "sidebar");
  await act(async () => root.unmount());
  root = createRoot(document.querySelector("#root")!);
  await render();
  expect(portal().dataset.theme).toBe("dark");
  expect(portal().style.fontFamily).toBe("monospace");
  expect(portal().querySelector(":scope > .full")).not.toBeNull();
  expect(
    portal().querySelector('[data-navigation="sidebar"] nav'),
  ).not.toBeNull();
  await click("Businesses");
  expect(portal().querySelector("nav [aria-pressed=true]")?.textContent).toBe(
    "Businesses",
  );
});

it("uses appearance preferences even when persistent storage fails", async () => {
  localStorage.setItem("cbm.appearance", "unreadable");
  request.mockResolvedValue(json({ stage: "setup_required" }));
  await render();
  const storage = vi
    .spyOn(Storage.prototype, "setItem")
    .mockImplementation(() => {
      throw new DOMException("Storage quota exceeded", "QuotaExceededError");
    });
  try {
    await act(async () => {
      const control = document.querySelector<HTMLSelectElement>(
        'header select[name="theme"]',
      )!;
      control.value = "light";
      control.dispatchEvent(new Event("change", { bubbles: true }));
    });
    expect(document.querySelector(".portal")?.getAttribute("data-theme")).toBe(
      "light",
    );
  } finally {
    storage.mockRestore();
  }
});

it("configures the portal, creates its owner, signs in, manages a service and signs out", async () => {
  let page = 1;
  let saved = false;
  let capabilitySaved = false;
  request.mockImplementation(async (path, body) => {
    switch (path) {
      case "installation":
        return json({ stage: "setup_required" });
      case "installation/setup":
        return json({ stage: "owner_required" });
      case "installation/owner":
        return json({ stage: "ready", user_code: "AA2345" });
      case "login":
      case "logout":
        return new Response(null, { status: 204 });
      case "workspace-access":
        return json(adminWorkspace);
      case "services/categories":
        return json({ B1: "Communications" });
      case "services":
        expect(body?.name).toBe("Messaging");
        saved = true;
        return json({ id: "first" });
      case "services/first/capabilities":
        expect(body?.name).toBe("Email");
        capabilitySaved = true;
        return json({ id: "child" });
      case "services/first/capabilities?page=1":
        return json({
          data: capabilitySaved
            ? [{ id: "child", name: "Email", purpose: "Deliver email" }]
            : [],
          next_page_url: capabilitySaved ? "/next" : null,
        });
      case "services/first/capabilities?page=2":
        return json({ data: [], next_page_url: null });
      case "services?page=1":
      case "services?page=2":
        page = path.endsWith("1") ? 1 : 2;
        return json({
          data:
            saved && page === 1
              ? [{ id: "first", name: "Messaging", purpose: "Delivery" }]
              : [],
          next_page_url: page === 1 ? "/next" : null,
        });
      default:
        throw new Error(path);
    }
  });
  await render();
  input("legal_name", "Operator");
  input("professional_name", "Portal");
  input("secret", "installation-code");
  await submit();
  expect(request).toHaveBeenCalledWith(
    "installation/setup",
    { legal_name: "Operator", professional_name: "Portal" },
    "installation-code",
  );
  input("name", "Owner");
  input("email", "owner@example.test");
  input("password", "example password");
  input("secret", "installation-code");
  await submit();
  expect(document.body.textContent).toContain("AA2345");
  input("code", "AA2345");
  input("email", "owner@example.test");
  input("password", "example password");
  await submit();
  expect(document.body.textContent).toContain("No services registered");
  for (const select of document.querySelectorAll<HTMLSelectElement>(
    "header select",
  )) {
    await act(async () => {
      select.selectedIndex = 1;
      select.dispatchEvent(new Event("change", { bubbles: true }));
    });
  }
  input("name", "Messaging");
  input("purpose", "Delivery");
  input("scopes", "Tenant-scoped");
  await submit();
  await click("Messaging");
  input("purpose", "Delivery");
  const form = document.querySelector<HTMLFormElement>(
    'form[data-kind="capability"]',
  )!;
  form.querySelector<HTMLInputElement>('[name="name"]')!.value = "Email";
  await submit('form[data-kind="capability"]');
  expect(
    document.querySelector('[aria-label="Registered capabilities"]')
      ?.textContent,
  ).toContain("Email");
  await click("Next capabilities");
  expect(document.body.textContent).toContain(
    "No capabilities registered for this service.",
  );
  await click("Previous capabilities");
  expect(document.body.textContent).toContain("Capability registered");
  await click("Next");
  expect(page).toBe(2);
  expect(document.querySelector('form[data-kind="capability"]')).toBeNull();
  await click("Previous");
  expect(page).toBe(1);
  await click("Sign out");
  expect(document.querySelector("h1")?.textContent).toBe("Sign in");
});

it("displays a failed login and keeps the sign-in form available", async () => {
  request
    .mockResolvedValueOnce(json({ stage: "ready" }))
    .mockRejectedValue(new Error("Sign-in denied"));
  await render();
  await submit();
  expect(document.body.textContent).toContain("Sign-in denied");
});

it("opens only the assigned workspace without service catalogue access", async () => {
  request.mockImplementation(async (path) => {
    if (path === "installation") return json({ stage: "ready" });
    if (path === "workspace-access")
      return json({ ...adminWorkspace, administration: ["commercial"] });
    if (path === "logout") return new Response(null, { status: 204 });
    throw new Error(path);
  });
  await render();
  expect(document.querySelector("h1")?.textContent).toBe(
    "Commercial management",
  );
  expect(document.querySelector("nav")?.textContent).toBe("Commercial");
  expect(request).not.toHaveBeenCalledWith("services/categories");
  await click("Sign out");
  expect(document.querySelector("h1")?.textContent).toBe("Sign in");
});

it("opens only the assigned Sub-Portal directory and paginates its Organizations", async () => {
  request.mockImplementation(async (path) => {
    if (path === "installation") return json({ stage: "ready" });
    if (path === "workspace-access")
      return json({
        ...adminWorkspace,
        administration: [],
        provider_directory: { id: "provider", professional_name: "Provider A" },
      });
    if (path === "provider-organizations?page=1")
      return json({
        data: [
          {
            id: "org",
            professional_name: "Served",
            operational_state: "Active",
            tenant_id: "tenant",
          },
        ],
        next_page_url: "/next",
      });
    if (path === "provider-organizations?page=2")
      return json({ data: [], next_page_url: null });
    throw new Error(path);
  });
  await render();
  expect(
    document.querySelector('[aria-label="Sub-Portal workspace"]')?.textContent,
  ).toContain("Provider A");
  expect(
    document.querySelector('[aria-label="Served Organizations"]')?.textContent,
  ).toContain("Served");
  expect(document.querySelector("nav")).toBeNull();
  await click("Next Organizations");
  expect(document.body.textContent).toContain("No Organizations on this page.");
  await click("Previous Organizations");
  expect(request).not.toHaveBeenCalledWith("services/categories");
});

it("provisions, reissues and terminates Sub-Portal user accounts", async () => {
  request.mockImplementation(async (path, body) => {
    if (path === "installation") return json({ stage: "ready" });
    if (path === "workspace-access")
      return json({ ...adminWorkspace, administration: ["provider_users"] });
    if (path === "providers/provider/users") {
      expect(body).toEqual({
        email: "staff@example.test",
        permission: "provider.organizations.view",
      });
      return json({ user_code: "AA6190", delivery: "sent" });
    }
    if (path === "providers/provider/users/reissue") {
      expect(body).toEqual({ code: "AA6190" });
      return json({ delivery: "failed" });
    }
    if (path === "providers/provider/users/revoke") {
      expect(body).toEqual({ code: "AA6190", reason: "Access withdrawn" });
      return json({ state: "Terminated" });
    }
    if (path === "providers/provider/users/recover") {
      expect(body).toEqual({
        code: "AA6190",
        reason: "Verified staff account",
      });
      return json({ delivery: "sent" });
    }
    throw new Error(path);
  });
  await render();
  const providerForm = (operation: string) =>
    'form[data-operation="provider-' + operation + '"]';
  const fill = (operation: string, field: string, value: string) => {
    document.querySelector<HTMLInputElement>(
      providerForm(operation) + ' input[name="' + field + '"]',
    )!.value = value;
  };
  fill("create", "provider", "provider");
  fill("create", "email", "staff@example.test");
  document.querySelector<HTMLInputElement>(
    providerForm("create") + ' input[name="permission"]',
  )!.checked = true;
  await submit(providerForm("create"));
  expect(document.body.textContent).toContain("AA6190");
  fill("reissue", "provider", "provider");
  fill("reissue", "code", "AA6190");
  await submit(providerForm("reissue"));
  expect(document.body.textContent).toContain("Email failed.");
  const recovery = 'form[data-operation="account-recovery"]';
  document.querySelector<HTMLInputElement>(
    recovery + ' [name="scope"]',
  )!.value = "provider";
  document.querySelector<HTMLInputElement>(recovery + ' [name="code"]')!.value =
    "AA6190";
  document.querySelector<HTMLInputElement>(
    recovery + ' [name="reason"]',
  )!.value = "Verified staff account";
  await submit(recovery);
  expect(document.body.textContent).toContain("Temporary password sent");
  fill("revoke", "provider", "provider");
  fill("revoke", "code", "AA6190");
  fill("revoke", "reason", "Access withdrawn");
  await submit(providerForm("revoke"));
  expect(document.body.textContent).toContain("Sub-Portal account terminated.");
});

it("binds Organization onboarding to the authenticated tenant", async () => {
  request.mockImplementation(async (path, body) => {
    if (path === "installation") return json({ stage: "ready" });
    if (path === "workspace-access") {
      return json({
        ...adminWorkspace,
        administration: [],
        organization_onboarding: {
          tenant_id: "my-tenant",
          professional_name: "My Organization",
        },
      });
    }
    if (path === "tenants/my-tenant/organization-users") {
      expect(body).toEqual({
        email: "staff@example.test",
        business_id: "shop",
        actions: ["mapping.initiate"],
      });
      return json({ user_code: "AA3000", delivery: "sent" });
    }
    throw new Error(path);
  });
  await render();
  const workspace = document.querySelector(
    '[aria-label="Organization onboarding"]',
  )!;
  expect(workspace.textContent).toContain("My Organization");
  expect(workspace.querySelector('input[name="tenant"]')).toBeNull();
  expect(
    workspace.querySelector('input[value="organization_users.provision"]'),
  ).toBeNull();
  expect(workspace.querySelector('form[data-operation="policy"]')).toBeNull();
  input("email", "staff@example.test");
  input("business_id", "shop");
  workspace.querySelector<HTMLInputElement>(
    'input[value="mapping.initiate"]',
  )!.checked = true;
  await submit(
    '[aria-label="Organization onboarding"] form:not([data-operation])',
  );
  expect(workspace.textContent).toContain("User Code: AA3000");
});

it("issues recovery only in the assigned Organization and reports the delivery outcome", async () => {
  let deliveries = 0;
  request.mockImplementation(async (path, body) => {
    if (path === "installation") return json({ stage: "ready" });
    if (path === "workspace-access")
      return json({
        ...adminWorkspace,
        administration: [],
        organization_recovery: {
          tenant_id: "tenant",
          professional_name: "Organization A",
        },
      });
    if (path === "tenants/tenant/organization-users/recover") {
      expect(body).toEqual({
        code: "AA4101",
        reason: "Verified account holder",
      });
      return json({ delivery: deliveries++ === 0 ? "failed" : "sent" });
    }
    throw new Error(path);
  });
  await render();
  const form = 'form[data-operation="account-recovery"]';
  expect(
    document.querySelector('[aria-label="Organization account recovery"]')
      ?.textContent,
  ).toContain("Organization A");
  expect(document.querySelector(form + ' input[name="scope"]')).toBeNull();
  input("code", "AA4101");
  input("reason", "Verified account holder");
  await submit(form);
  expect(document.body.textContent).toContain("Email delivery failed");
  await submit(form);
  expect(document.body.textContent).toContain("Temporary password sent");
});

it("keeps sign out available for an authenticated account without assignments", async () => {
  request.mockImplementation(async (path) => {
    if (path === "installation") return json({ stage: "ready" });
    if (path === "workspace-access")
      return json({ ...adminWorkspace, administration: [] });
    throw new Error(path);
  });
  await render();
  expect(document.body.textContent).toContain(
    "No workspace is assigned to this account.",
  );
  expect(document.querySelector('input[name="password"]')).toBeNull();
  expect(document.body.textContent).toContain("Sign out");
});

it("filters the Audit Trail and loads older events without requesting catalogue data", async () => {
  request.mockImplementation(async (path) => {
    if (path === "installation") return json({ stage: "ready" });
    if (path === "workspace-access")
      return json({ ...adminWorkspace, administration: ["audit"] });
    if (path === "audit-trail?action=business.created&subject_id=org") {
      return json({
        data: [
          {
            id: "event",
            action: "business.created",
            recorded_at: "2026-11-20T00:00:00Z",
            actor_id: "owner",
            subject_id: "org",
          },
          {
            id: "system-event",
            action: "business.created",
            recorded_at: "2026-11-19T00:00:00Z",
            actor_id: null,
            subject_id: null,
          },
        ],
        next_before_id: "event",
      });
    }
    if (
      path ===
      "audit-trail?action=business.created&subject_id=org&before_id=event"
    )
      return json({ data: [], next_before_id: null });
    throw new Error("Audit Trail unavailable");
  });
  await render();
  expect(document.querySelector("h1")?.textContent).toBe("Audit Trail");
  input("action", "business.created");
  input("subject_id", "org");
  await submit('form[data-operation="audit-search"]');
  expect(
    document.querySelector('[aria-label="Audit events"]')?.textContent,
  ).toContain("business.created");
  expect(document.querySelector("time")?.getAttribute("datetime")).toBe(
    "2026-11-20T00:00:00Z",
  );
  expect(
    document.querySelector('[aria-label="Audit events"]')?.textContent,
  ).toContain("Unavailable");
  await click("Older events");
  expect(document.body.textContent).toContain("No events match these filters.");
  input("subject_id", "");
  await submit('form[data-operation="audit-search"]');
  expect(document.body.textContent).toContain("Audit Trail unavailable");
  expect(request).not.toHaveBeenCalledWith("services/categories");
});
it("reports an invalid installation state", async () => {
  request.mockResolvedValue(json({ stage: "unknown" }));
  await render();
  expect(document.body.textContent).toContain(
    "Installation status unavailable",
  );
});
it("reports catalogue and sign-out errors", async () => {
  request
    .mockResolvedValueOnce(json({ stage: "ready" }))
    .mockResolvedValueOnce(json(adminWorkspace))
    .mockResolvedValueOnce(json({ A1: "Identity" }))
    .mockRejectedValue(new Error("Service unavailable"));
  await render();
  expect(document.body.textContent).toContain("Service unavailable");
  await submit();
  expect(document.body.textContent).toContain("Service unavailable");
  await click("Sign out");
  expect(document.querySelector("h1")?.textContent).toBe("Service catalogue");
});

it("creates an Organization, displays its ownership and creates a Shop", async () => {
  let requestedPage = 0;
  request.mockImplementation(async (path, body) => {
    switch (path) {
      case "installation":
        return json({ stage: "ready" });
      case "workspace-access":
        return json(adminWorkspace);
      case "services/categories":
        return json({ A1: "Identity" });
      case "services?page=1":
        return json({ data: [] });
      case "businesses?page=1":
      case "businesses?page=2":
        requestedPage = path.endsWith("1") ? 1 : 2;
        return json({
          data: [
            {
              id: "business-1",
              professional_name: "North Shop",
              kind: "Shop",
              legal_name: "North",
              provider_id: "provider-1",
              tenant_id: "tenant-1",
              parent_id: "organization-1",
              owners: [{ name: "Asha", owner_id: "owner-1" }],
            },
          ],
          next_page_url: requestedPage === 1 ? "/next" : null,
        });
      case "businesses":
        if (body?.professional_name === "Duplicate")
          throw Error("Unable to register");
        return json({ id: "new-business" });
      default:
        throw Error(path);
    }
  });
  await render();
  await click("Businesses");
  expect(document.body.textContent).toContain("Asha (owner-1)");
  await click("Next");
  expect(requestedPage).toBe(2);
  await click("Previous");
  expect(requestedPage).toBe(1);
  input("professional_name", "Western Network");
  input("legal_name", "Western Limited");
  input("provider_id", "provider-1");
  input("owner_ids", "owner-1, owner-2");
  await submit();
  expect(request).toHaveBeenCalledWith("businesses", {
    kind: "Organization",
    professional_name: "Western Network",
    legal_name: "Western Limited",
    provider_id: "provider-1",
    owner_ids: ["owner-1", "owner-2"],
  });
  const kind = document.querySelector<HTMLSelectElement>(
    "section > label select",
  )!;
  await act(async () => {
    kind.value = "Shop";
    kind.dispatchEvent(new Event("change", { bubbles: true }));
  });
  input("professional_name", "New Shop");
  input("parent_id", "organization-1");
  await submit();
  expect(request).toHaveBeenCalledWith("businesses", {
    kind: "Shop",
    professional_name: "New Shop",
    parent_id: "organization-1",
  });
  input("professional_name", "Duplicate");
  input("parent_id", "organization-1");
  await submit();
  expect(document.body.textContent).toContain("Unable to register");
  await click("Services");
  expect(document.querySelector("h1")?.textContent).toBe("Service catalogue");
});

it("reports an unavailable business directory", async () => {
  request
    .mockResolvedValueOnce(json({ stage: "ready" }))
    .mockResolvedValueOnce(json(adminWorkspace))
    .mockResolvedValueOnce(json({ A1: "Identity" }))
    .mockResolvedValueOnce(json({ data: [] }))
    .mockRejectedValueOnce(new Error("Directory unavailable"));
  await render();
  await click("Businesses");
  expect(document.body.textContent).toContain("Directory unavailable");
});

it("loads the customer mapping workspace and sends each action to its assigned business", async () => {
  request.mockImplementation(async (path) => {
    if (path === "installation") return json({ stage: "ready" });
    if (path === "workspace-access")
      return json({
        administration: [],
        delegations: [
          {
            id: "grant",
            tenant_id: "tenant",
            business_id: "shop",
            professional_name: "My Shop",
          },
        ],
        mapping_contexts: [
          "initiate",
          "verify",
          "confirm",
          "end",
          "history.view",
        ].map((action) => ({
          id: action === "verify" ? "distributor" : "shop",
          professional_name: "My business",
          action: "mapping." + action,
        })),
      });
    if (path === "shops/shop/mappings")
      return json({ id: "m1", code: "shop-code" });
    if (path.endsWith("/verify"))
      return json({ id: "m1", code: "distributor-code" });
    if (path.endsWith("/confirm")) return json({ state: "Active" });
    if (path === "businesses/shop/mappings/m1/history?page=1")
      return json({
        data: [
          {
            id: "event",
            action: "activated",
            recorded_at: "2026-11-20T00:00:00Z",
          },
        ],
        next_page_url: "/next",
      });
    if (path === "businesses/shop/mappings/m1/history?page=2")
      return json({ data: [], next_page_url: null });
    if (path.endsWith("/end")) throw new Error("Mapping already ended");
    if (path === "logout") return new Response(null, { status: 204 });
    throw Error(path);
  });
  await render();
  expect(
    document.querySelector('[aria-label="Active delegations"]')?.textContent,
  ).toContain("My Shop");
  input("party", "distributor");
  await submit("form:not([data-operation])");
  expect(document.body.textContent).toContain("Shop ID: shop");
  expect(document.body.textContent).toContain("shop-code");
  const selection =
    document.querySelector<HTMLSelectElement>("section select")!;
  async function select(value: string) {
    await act(async () => {
      selection.value = value;
      selection.dispatchEvent(new Event("change", { bubbles: true }));
    });
  }
  await select("1");
  expect(document.querySelector('[name="mapping_id"]')).toBeNull();
  input("party", "shop");
  document.querySelector<HTMLInputElement>(
    'form:not([data-operation]) [name="code"]',
  )!.value = "shop-code";
  await submit("form:not([data-operation])");
  expect(request).toHaveBeenCalledWith(
    "distributors/distributor/mappings/verify",
    { shop_id: "shop", code: "shop-code" },
  );
  await select("2");
  expect(document.querySelector('[name="party"]')).toBeNull();
  document.querySelector<HTMLInputElement>(
    'form:not([data-operation]) [name="code"]',
  )!.value = "distributor-code";
  await submit("form:not([data-operation])");
  expect(request).toHaveBeenCalledWith("shops/shop/mappings/confirm", {
    code: "distributor-code",
  });
  expect(document.body.textContent).toContain("Mapping status: Active");
  await select("3");
  input("mapping_id", "m1");
  await submit("form:not([data-operation])");
  expect(document.body.textContent).toContain("Mapping already ended");
  await select("4");
  input("mapping_id", "m1");
  await submit("form:not([data-operation])");
  expect(
    document.querySelector('[aria-label="Mapping History"]')?.textContent,
  ).toContain("activated");
  await click("Next history page");
  expect(document.body.textContent).toContain("No history on this page.");
  await click("Previous history page");
  input("mapping_id", "invalid");
  await submit("form:not([data-operation])");
  expect(document.body.textContent).toContain(
    "businesses/shop/mappings/invalid/history?page=1",
  );
  await click("Sign out");
  expect(document.querySelector("h1")?.textContent).toBe("Sign in");
});

it("configures account setup and provisions an authorized Organization user", async () => {
  request.mockImplementation(async (path, body) => {
    switch (path) {
      case "installation":
        return json({ stage: "ready" });
      case "workspace-access":
        return json(adminWorkspace);
      case "services/categories":
        return json({ A1: "Identity" });
      case "services?page=1":
        return json({ data: [] });
      case "tenants/tenant/credential-policy":
        return json({
          mode: body?.mode ?? "otp",
          source: body ? "organization" : "sub_portal",
        });
      case "providers/portal/credential-default":
        return json({ mode: "otp" });
      case "tenants/tenant/organization-users":
        return json({ user_code: "AB1234", delivery: "failed" });
      case "tenants/tenant/organization-users/reissue":
        if (body?.code === "AB1235") throw new Error("User unavailable");
        return json({ delivery: "sent" });
      case "tenants/tenant/organization-users/revoke-onboarding":
        expect(body).toEqual({ code: "AB1234" });
        return json({ status: "revoked" });
      default:
        throw new Error(path);
    }
  });
  await render();
  await click("Organization users");
  const policy = "form[data-operation=policy]";
  const resend = "form[data-operation=reissue]";
  const create = "form:not([data-operation])";
  function fillIn(form: string, field: string, value: string) {
    document.querySelector<HTMLInputElement>(
      form + ' [name="' + field + '"]',
    )!.value = value;
  }
  const provider = "form[data-operation=provider]";
  const lookup = "form[data-operation=lookup]";
  fillIn(provider, "provider", "portal");
  fillIn(provider, "mode", "otp");
  await submit(provider);
  expect(request).toHaveBeenCalledWith("providers/portal/credential-default", {
    mode: "otp",
  });
  fillIn(lookup, "tenant", "tenant");
  await submit(lookup);
  expect(document.body.textContent).toContain(
    "Email one-time code · Sub-Portal",
  );
  fillIn(policy, "tenant", "tenant");
  fillIn(policy, "mode", "both");
  await submit(policy);
  expect(request).toHaveBeenCalledWith("tenants/tenant/credential-policy", {
    mode: "both",
  });
  fillIn(create, "tenant", "tenant");
  fillIn(create, "email", "authorized@example.test");
  fillIn(create, "business_id", "shop");
  document.querySelector<HTMLInputElement>(
    create + ' [value="mapping.initiate"]',
  )!.checked = true;
  await submit(create);
  expect(request).toHaveBeenCalledWith("tenants/tenant/organization-users", {
    email: "authorized@example.test",
    business_id: "shop",
    actions: ["mapping.initiate"],
  });
  expect(document.body.textContent).toContain("User Code: AB1234");
  fillIn(resend, "tenant", "tenant");
  fillIn(resend, "code", "AB1234");
  await submit(resend);
  expect(document.body.textContent).toContain("Setup credentials delivered");
  fillIn(resend, "code", "AB1235");
  await submit(resend);
  expect(document.body.textContent).toContain("User unavailable");
  const revoke = 'form[data-operation="revoke-onboarding"]';
  fillIn(revoke, "tenant", "tenant");
  fillIn(revoke, "code", "AB1234");
  await submit(revoke);
  expect(document.body.textContent).toContain(
    "Organization onboarding permission revoked.",
  );
});

it("sets a permanent password through the separate restricted setup screen", async () => {
  request.mockImplementation(async (path, body) => {
    if (path === "installation") return json({ stage: "ready" });
    if (path === "workspace-access")
      throw new PortalRequestError("Sign in first", 401);
    if (path === "organization-setup/verify") {
      if (!body?.otp) throw new Error("Code required");
      return json({ stage: "set_password" });
    }
    if (path === "organization-setup/complete") return json({ stage: "ready" });
    throw new Error("Sign in first");
  });
  await render();
  await click("Set up account");
  await click("Back to sign in");
  await click("Set up account");
  input("code", "AB1234");
  await submit();
  expect(document.body.textContent).toContain("Code required");
  input("otp", "001234");
  await submit();
  input("password", "password-for-tests-only");
  input("password_confirmation", "password-for-tests-only");
  await submit();
  expect(document.body.textContent).toContain("Account setup complete");
});

it("loads and changes individual business access controls independently", async () => {
  const path = "tenants/tenant/organization-users/AA2000/business-access/shop";
  request.mockImplementation(async (url, body) => {
    if (url === "installation") return json({ stage: "ready" });
    if (url === "workspace-access") return json(adminWorkspace);
    if (url === "services/categories") return json({ A1: "Identity" });
    if (url === "services?page=1") return json({ data: [] });
    if (url === path && body === undefined)
      return json({
        associated: true,
        in_scope: true,
        actions: ["mapping.initiate"],
      });
    if (url === path) return json(body);
    throw new Error("Business access unavailable");
  });
  await render();
  await click("Organization users");
  const lookup = "form[data-operation=access-lookup]";
  const edit = "form[data-operation=access-update]";
  const field = (form: string, name: string) =>
    document.querySelector<HTMLInputElement>(form + ' [name="' + name + '"]')!;
  field(lookup, "tenant").value = "tenant";
  field(lookup, "code").value = "AA2000";
  field(lookup, "business").value = "shop";
  await submit(lookup);
  expect(field(edit, "associated").checked).toBe(true);
  field(edit, "in_scope").checked = false;
  await submit(edit);
  expect(request).toHaveBeenCalledWith(path, {
    associated: true,
    in_scope: false,
    actions: ["mapping.initiate"],
  });
  expect(document.body.textContent).toContain("Business access updated");
  request.mockRejectedValueOnce(new Error("Change not saved"));
  await submit(edit);
  expect(document.body.textContent).toContain("Change not saved");
  field(lookup, "business").value = "other";
  await submit(lookup);
  expect(document.querySelector(edit)).toBeNull();
  expect(document.body.textContent).toContain("Business access unavailable");
});

it("grants and revokes a business access delegation from the administration screen", async () => {
  request.mockImplementation(async (path, body) => {
    if (path === "installation") return json({ stage: "ready" });
    if (path === "workspace-access") return json(adminWorkspace);
    if (path === "services/categories") return json({ A1: "Identity" });
    if (path === "services?page=1") return json({ data: [] });
    if (path === "tenants/tenant/businesses/shop/access-delegations") {
      expect(body).toEqual({
        code: "AA7654",
        minutes: "15",
        actions: ["mapping.initiate"],
      });
      return json({ id: "grant" });
    }
    if (path === "organization-access-delegations/grant/revoke")
      return json({ status: "revoked" });
    throw Error("Delegation unavailable");
  });
  await render();
  await click("Organization users");
  const create = "form[data-operation=delegation-create]";
  const revoke = "form[data-operation=delegation-revoke]";
  const fill = (form: string, name: string, value: string) => {
    document.querySelector<HTMLInputElement>(
      form + ' input[name="' + name + '"]',
    )!.value = value;
  };
  fill(create, "tenant", "tenant");
  fill(create, "business", "shop");
  fill(create, "code", "AA7654");
  fill(create, "minutes", "15");
  document.querySelector<HTMLInputElement>(
    create + ' input[value="mapping.initiate"]',
  )!.checked = true;
  await submit(create);
  expect(document.body.textContent).toContain("Delegation ID: grant");
  fill(revoke, "grant", "grant");
  await submit(revoke);
  expect(document.body.textContent).toContain("Delegation revoked.");
  fill(revoke, "grant", "missing");
  await submit(revoke);
  expect(document.body.textContent).toContain("Delegation unavailable");
});

it("registers a role and changes the selected user's assignment", async () => {
  const base = "tenants/tenant/businesses/shop/roles";
  let created = false;
  let pending = false;
  const approvals = "tenants/tenant/businesses/shop/role-approvals";
  request.mockImplementation(async (path, body) => {
    if (path === "installation") return json({ stage: "ready" });
    if (path === "workspace-access") return json(adminWorkspace);
    if (path === "services/categories") return json({ A1: "Identity" });
    if (path === "services?page=1") return json({ data: [] });
    if (path === base + "?page=1")
      return json({
        data: created
          ? [
              {
                id: "role1",
                name: "Shop liaison",
                actions: ["mapping.confirm"],
              },
            ]
          : [],
        next_page_url: created ? "next" : null,
      });
    if (path === base + "?page=2")
      return json({ data: [], next_page_url: null });
    if (path === base) {
      expect(body).toEqual({
        name: "Shop liaison",
        actions: ["mapping.confirm"],
      });
      created = true;
      return json({ id: "role1" });
    }
    if (path === approvals + "/policy") {
      expect(body).toEqual({
        operation: "assign",
        stages: [{ required: 2, reviewers: ["AA0011", "AA0012"] }],
      });
      return json({ stage_count: 1 });
    }
    if (path === approvals + "?page=1")
      return json({
        data: pending
          ? [
              {
                id: "a1",
                assign: true,
                stage: 0,
                role: "Shop liaison",
                code: "AA4010",
              },
              {
                id: "a2",
                assign: false,
                stage: 0,
                role: "Spare role",
                code: "AA4011",
              },
            ]
          : [],
        next_page_url: "next",
      });
    if (path === approvals + "?page=2")
      return json({ data: [], next_page_url: null });
    if (path === approvals + "/a1/decision") {
      expect(body).toEqual({ decision: "approve" });
      return json({ status: "pending" });
    }
    if (path === approvals + "/a2/decision") {
      expect(body).toEqual({ decision: "reject" });
      pending = false;
      return json({ status: "rejected" });
    }
    if (path.endsWith("/role1/assign")) {
      pending = true;
      return Response.json({ id: "a1", status: "pending" }, { status: 202 });
    }
    if (path.endsWith("/role1/revoke")) return json({ assigned: false });
    throw Error("Business unavailable");
  });
  await render();
  await click("Organization users");
  const lookup = "form[data-operation=role-lookup]";
  const create = "form[data-operation=role-create]";
  const assignment = "form[data-operation=role-assignment]";
  const set = (form: string, name: string, value: string) => {
    document.querySelector<HTMLInputElement>(
      form + ' [name="' + name + '"]',
    )!.value = value;
  };
  set(lookup, "tenant", "tenant");
  set(lookup, "business", "shop");
  await submit(lookup);
  set(create, "name", "Shop liaison");
  document.querySelector<HTMLInputElement>(
    create + ' [value="mapping.confirm"]',
  )!.checked = true;
  await submit(create);
  expect(document.body.textContent).toContain("Role created.");
  await click("Next roles");
  expect(document.querySelector(assignment)).toBeNull();
  await click("Previous roles");
  set(assignment, "code", "AA4010");
  await click("Assign role");
  expect(request).toHaveBeenCalledWith(base + "/role1/assign", {
    code: "AA4010",
  });
  expect(document.body.textContent).toContain("Awaiting approval: a1");
  const policy = "form[data-operation=role-approval-policy]";
  document.querySelector<HTMLTextAreaElement>(policy + " textarea")!.value =
    "wrong";
  await submit(policy);
  expect(document.body.textContent).toContain("Enter the required count");
  document.querySelector<HTMLTextAreaElement>(policy + " textarea")!.value =
    "2: AA0011, AA0012";
  await submit(policy);
  expect(document.body.textContent).toContain(
    "Approval policy saved. Stages: 1",
  );
  await click("Load pending approvals");
  expect(document.body.textContent).toContain("Assign Shop liaison");
  await click("Next approvals");
  expect(document.body.textContent).not.toContain("Assign Shop liaison");
  await click("Previous approvals");
  await click("Approve");
  expect(document.body.textContent).toContain("Request status: pending");
  const reject = document.querySelectorAll<HTMLButtonElement>(
    '[aria-label="Pending role changes"] li button',
  )[3];
  await act(async () => reject.click());
  expect(document.body.textContent).toContain("Request status: rejected");
  set(assignment, "code", "AA4010");
  await click("Remove role");
  expect(request).toHaveBeenCalledWith(base + "/role1/revoke", {
    code: "AA4010",
  });
  set(lookup, "business", "unknown");
  await submit(lookup);
  expect(document.body.textContent).toContain("Business unavailable");
});

it("manages an Organization account state independently of business access", async () => {
  const base = "tenants/tenant/organization-users/AA7111/lifecycle";
  request.mockImplementation(async (path, body) => {
    if (path === "installation") return json({ stage: "ready" });
    if (path === "workspace-access") return json(adminWorkspace);
    if (path === "services/categories") return json({ A1: "Identity" });
    if (path === "services?page=1") return json({ data: [] });
    if (path === base && !body)
      return json({
        state: "Active",
        allowed_transitions: ["Suspended", "Terminated"],
      });
    if (path === base && body?.state === "Suspended")
      return json({
        state: "Suspended",
        allowed_transitions: ["Active", "Terminated"],
      });
    if (path === base && body?.state === "Terminated")
      return json({ state: "Terminated", allowed_transitions: [] });
    throw Error("Account unavailable");
  });
  await render();
  await click("Organization users");
  const lookup = "form[data-operation=account-state-lookup]";
  const update = "form[data-operation=account-state-update]";
  const field = (form: string, name: string) =>
    document.querySelector<HTMLInputElement>(form + ' [name="' + name + '"]')!;
  field(lookup, "tenant").value = "tenant";
  field(lookup, "code").value = "AA7111";
  await submit(lookup);
  expect(document.body.textContent).toContain("AA7111 · Active");
  field(update, "reason").value = "Review";
  await submit(update);
  expect(request).toHaveBeenCalledWith(base, {
    state: "Suspended",
    expected_state: "Active",
    reason: "Review",
  });
  const select = field(update, "target");
  await act(async () => {
    select.value = "Terminated";
    select.dispatchEvent(new Event("change", { bubbles: true }));
  });
  expect(
    document.querySelector<HTMLButtonElement>(update + " button")!.disabled,
  ).toBe(true);
  field(update, "reason").value = "Close account";
  await act(async () =>
    document
      .querySelector<HTMLInputElement>(update + ' input[type="checkbox"]')!
      .click(),
  );
  await submit(update);
  expect(document.body.textContent).toContain("AA7111 · Terminated");
  expect(document.querySelector(update)).toBeNull();
  field(lookup, "code").value = "AA7112";
  await submit(lookup);
  expect(document.body.textContent).toContain("Account unavailable");
});

it("applies a business lifecycle transition and refreshes the business directory", async () => {
  const path = "businesses/test-org/lifecycle";
  let state = "Active";
  request.mockImplementation(async (url, body) => {
    if (url === "installation") return json({ stage: "ready" });
    if (url === "workspace-access") return json(adminWorkspace);
    if (url === "services/categories") return json({ A1: "Identity" });
    if (url === "services?page=1") return json({ data: [] });
    if (url === "businesses?page=1")
      return json({
        data: [
          {
            id: "test-org",
            kind: "Organization",
            professional_name: "Western Network",
            operational_state: state,
            owners: [],
          },
        ],
      });
    if (url === path && !body)
      return json({
        state,
        version: 0,
        allowed_transitions: ["Suspended", "Deactivated"],
      });
    if (url === path && typeof body?.operation_id === "string") {
      expect(body).toMatchObject({
        state: "Suspended",
        expected_version: 0,
        reason: "Temporary review",
      });
      state = "Suspended";
      return json({ state, changed_businesses: 2, changed_mappings: 1 });
    }
    throw Error("Business unavailable");
  });
  await render();
  await click("Businesses");
  await click("Load business state");
  expect(document.body.textContent).toContain("Current state: Active");
  const operation = "form[data-operation=business-lifecycle]";
  document.querySelector<HTMLTextAreaElement>(
    operation + ' [name="reason"]',
  )!.value = "Temporary review";
  await submit(operation);
  expect(document.body.textContent).toContain("New state: Suspended");
  expect(document.body.textContent).toContain("Mappings updated: 1");
});

it("previews a provider migration and submits only a valid reviewed scope", async () => {
  let eligible = false;
  request.mockImplementation(async (path, body) => {
    if (path === "installation") return json({ stage: "ready" });
    if (path === "workspace-access") return json(adminWorkspace);
    if (path === "services/categories") return json({ A1: "Identity" });
    if (path === "services?page=1" || path === "businesses?page=1")
      return json({ data: [] });
    if (path === "provider-migrations/preview")
      return json({
        tenants: [
          {
            tenant_id: "tenant",
            name: "Western Network",
            businesses: [{ id: "one" }],
          },
        ],
        blocked: eligible ? {} : { tenant: ["Policy mismatch"] },
        can_execute: eligible,
        fingerprint: "snapshot",
      });
    if (path === "provider-migrations/execute") {
      expect(body).toMatchObject({
        source_id: "original",
        destination_id: "destination",
        tenant_id: "tenant",
        fingerprint: "snapshot",
        reason: "Consolidation",
      });
      return json({ tenants_migrated: 1 });
    }
    throw Error("Provider unavailable");
  });
  await render();
  await click("Businesses");
  const preview = "form[data-operation=provider-migration-preview]";
  const execute = "form[data-operation=provider-migration-execute]";
  const fill = (form: string, name: string, value: string) =>
    (document.querySelector<HTMLInputElement>(
      form + ' [name="' + name + '"]',
    )!.value = value);
  fill(preview, "source_id", "original");
  fill(preview, "destination_id", "destination");
  fill(preview, "tenant_id", "tenant");
  await submit(preview);
  expect(document.body.textContent).toContain("Policy mismatch");
  expect(document.querySelector(execute)).toBeNull();
  eligible = true;
  await submit(preview);
  fill(execute, "reason", "Consolidation");
  await submit(execute);
  expect(document.body.textContent).toContain(
    "Migration completed. Tenants moved: 1",
  );
});

it("publishes a Plan Version and registers a subscription with explicit coverage", async () => {
  let automatic = false;
  request.mockImplementation(async (path, body) => {
    if (path === "installation") return json({ stage: "ready" });
    if (path === "workspace-access") return json(adminWorkspace);
    if (path === "services/categories") return json({ A1: "Identity" });
    if (path === "services?page=1") return json({ data: [] });
    if (path.startsWith("commercial?"))
      return json({
        data: [{ id: "saved", entitlements: [{ code: "portal.reports" }] }],
        next_page_url: path.endsWith("1") ? "next" : null,
      });
    if (path === "commercial/plans/plan/versions") {
      expect(body).toEqual({
        amount_paise: "12345",
        term_days: "30",
        entitlements: ["portal.reports"],
      });
      return json({ id: "version1", version: 1 });
    }
    if (path === "commercial/subscriptions") {
      expect(body).toEqual({
        account_id: "account",
        plan_version_id: "version1",
        starts_on: "2032-02-28",
        renewal_mode: automatic ? "automatic" : "manual",
        ...(automatic
          ? {
              renewal_lead_days: "5",
              renewal_grace_days: "3",
              renewal_grace_policy: "retain_features",
            }
          : {}),
        coverage_mode: "selected",
        business_ids: ["shop", "distributor"],
      });
      return json({ id: "subscription1", state: "Pending Activation" });
    }
    if (path === "commercial/accounts") return json({ id: "account" });
    if (path === "commercial/add_ons") {
      expect(body).toEqual({
        provider_id: "provider",
        family: "tenant",
        name: "Exports",
      });
      return json({ id: "exports" });
    }
    if (path === "commercial/add_ons/exports/versions") {
      expect(body).toEqual({
        plan_version_id: "version1",
        amount_paise: "100",
        entitlements: ["portal.exports"],
      });
      return json({ id: "exports-v1", version: 1 });
    }
    throw Error("Commercial record unavailable");
  });
  await render();
  await click("Commercial");
  const form = "form[data-operation=commercial-create]";
  const set = (field: string, value: string) => {
    document.querySelector<HTMLInputElement>(
      form + ' [name="' + field + '"]',
    )!.value = value;
  };
  const select = document.querySelector<HTMLSelectElement>(
    "section > label select",
  )!;
  const change = (value: string) =>
    act(async () => {
      select.value = value;
      select.dispatchEvent(new Event("change", { bubbles: true }));
    });
  set("tenant_id", "tenant");
  set("provider_id", "provider");
  set("billing_party", "Organization legal name");
  await submit(form);
  expect(document.body.textContent).toContain("Saved: account");
  await click("Next records");
  await click("Previous records");
  await change("versions");
  set("plan_id", "plan");
  set("amount_paise", "12345");
  set("term_days", "30");
  set("entitlements", "portal.reports");
  await submit(form);
  expect(document.body.textContent).toContain("Saved: version1");
  await change("subscriptions");
  set("account_id", "account");
  set("plan_version_id", "version1");
  set("starts_on", "2032-02-28");
  set("coverage_mode", "selected");
  set("business_ids", "shop, distributor");
  await submit(form);
  expect(document.body.textContent).toContain(
    "Pending Activation · Saved: subscription1",
  );
  automatic = true;
  await act(async () => {
    const mode = document.querySelector<HTMLSelectElement>(
      form + ' select[name="renewal_mode"]',
    )!;
    mode.value = "automatic";
    mode.dispatchEvent(new Event("change", { bubbles: true }));
  });
  set("account_id", "account");
  set("plan_version_id", "version1");
  set("starts_on", "2032-02-28");
  set("coverage_mode", "selected");
  set("business_ids", "shop, distributor");
  set("renewal_lead_days", "5");
  await act(async () => {
    const policy = document.querySelector<HTMLSelectElement>(
      '[data-control="renewal-grace-policy"]',
    )!;
    policy.value = "retain_features";
    policy.dispatchEvent(new Event("change", { bubbles: true }));
  });
  set("renewal_grace_days", "3");
  await submit(form);
  expect(
    document.querySelector(form + ' input[name="renewal_lead_days"]'),
  ).toBeNull();
  await change("add_ons");
  set("provider_id", "provider");
  set("name", "Exports");
  set("family", "tenant");
  await submit(form);
  expect(document.body.textContent).toContain("Saved: exports");
  await change("add_on_versions");
  set("add_on_id", "exports");
  set("plan_version_id", "version1");
  set("amount_paise", "100");
  set("entitlements", "portal.exports");
  await submit(form);
  expect(document.body.textContent).toContain("Saved: exports-v1");
  await change("plans");
  set("provider_id", "unknown");
  set("name", "Plan");
  await submit(form);
  expect(document.body.textContent).toContain("Commercial record unavailable");
  await click("Load commercial records");
  expect(
    document.querySelector('[aria-label="Commercial records"]')?.textContent,
  ).toContain("saved");
});

it("changes a subscription state and resolves features for a covered business", async () => {
  let state = "Pending Activation";
  let settled = false;
  let renewed = false;
  request.mockImplementation(async (path, body) => {
    if (path === "installation") return json({ stage: "ready" });
    if (path === "workspace-access") return json(adminWorkspace);
    if (path === "services/categories") return json({ A1: "Identity" });
    if (path === "services?page=1") return json({ data: [] });
    if (path === "commercial?section=subscriptions&page=1")
      return json({
        data: [
          {
            id: "contract",
            state,
            effective_state: state,
            ends_before: "2032-03-29",
            plan_version_id: "v1",
            renewal_id: renewed ? "next-term" : null,
          },
          {
            id: "ended",
            state: "Active",
            effective_state: "Expired",
            renewal_id: "successor",
          },
        ],
        next_page_url: null,
      });
    if (path === "commercial/subscriptions/contract/add_ons") {
      expect(body).toEqual({
        business_id: "shop",
        add_on_version_id: "exports-v1",
      });
      return json(body);
    }
    if (path === "commercial/subscriptions/contract/transition") {
      expect(body?.expected_state).toBe(state);
      if (!settled)
        throw new Error("Settle the term invoice before activation.");
      state = body?.action === "activate" ? "Active" : "Suspended";
      return json({ id: "contract", state });
    }
    if (path === "commercial/subscriptions/contract/renew") {
      expect(body).toEqual({
        plan_version_id: "v2",
        reason: "Next term approved",
      });
      renewed = true;
      return json({ id: "next-term", starts_on: "2032-03-29" });
    }
    if (path === "commercial/businesses/shop/effective-entitlements")
      return json({
        business_id: "shop",
        feature_codes: state === "Active" ? ["portal.reports"] : [],
      });
    throw new Error(path);
  });
  await render();
  await click("Commercial");
  const select = document.querySelector<HTMLSelectElement>(
    "section > label select",
  )!;
  await act(async () => {
    select.value = "subscriptions";
    select.dispatchEvent(new Event("change", { bubbles: true }));
  });
  await click("Load commercial records");
  const transition = "form[data-operation=subscription-transition]";
  const lookup = "form[data-operation=effective-entitlements]";
  expect(document.querySelectorAll(transition)).toHaveLength(1);
  const attachment = "form[data-operation=subscription-add-on]";
  document.querySelector<HTMLInputElement>(
    attachment + ' input[name="business_id"]',
  )!.value = "shop";
  document.querySelector<HTMLInputElement>(
    attachment + ' input[name="add_on_version_id"]',
  )!.value = "exports-v1";
  await submit(attachment);
  expect(document.body.textContent).toContain(
    "Feature Add-on attached to the term.",
  );
  const apply = async (action: string, reason: string) => {
    input("action", action);
    input("reason", reason);
    await submit(transition);
  };
  await apply("activate", "Receipt checked");
  expect(document.body.textContent).toContain(
    "Settle the term invoice before activation.",
  );
  settled = true;
  await apply("activate", "Receipt checked");
  expect(request).toHaveBeenCalledWith(
    "commercial/subscriptions/contract/transition",
    {
      action: "activate",
      expected_state: "Pending Activation",
      reason: "Receipt checked",
    },
  );
  expect(document.body.textContent).toContain("Subscription state updated.");
  const renewal = "form[data-operation=subscription-renew]";
  expect(
    document.querySelector<HTMLInputElement>(
      renewal + ' input[name="plan_version_id"]',
    )?.value,
  ).toBe("v1");
  document.querySelector<HTMLInputElement>(
    renewal + ' input[name="plan_version_id"]',
  )!.value = "v2";
  document.querySelector<HTMLInputElement>(
    renewal + ' input[name="reason"]',
  )!.value = "Next term approved";
  await submit(renewal);
  expect(document.body.textContent).toContain(
    "Renewal saved: next-term · Starts 2032-03-29",
  );
  expect(document.querySelector(renewal)).toBeNull();
  input("business_id", "shop");
  await submit(lookup);
  expect(
    document.querySelector('[aria-label="Available features"]')?.textContent,
  ).toContain("portal.reports");
  await apply("suspend", "Commercial review");
  expect(
    document.querySelector('[aria-label="Available features"]'),
  ).toBeNull();
  await submit(lookup);
  expect(document.body.textContent).toContain(
    "No features are currently available.",
  );
  expect(document.querySelector(transition)?.textContent).toContain(
    "Reinstate",
  );
});

it("issues a billing record and records payments and reversals", async () => {
  let paid = 0;
  let results = 0;
  const invoice = () => ({
    id: "invoice1",
    status: paid ? "Partially Paid" : "Issued/Open",
    paid_paise: paid,
    outstanding_paise: 12345 - paid,
  });
  request.mockImplementation(async (url, body) => {
    if (url === "installation") return json({ stage: "ready" });
    if (url === "workspace-access") return json(adminWorkspace);
    if (url === "services/categories") return json({ A1: "Identity" });
    if (url === "services?page=1") return json({ data: [] });
    if (url === "commercial/invoices" && body) return json(invoice());
    if (url.startsWith("commercial/invoices?page="))
      return json({
        data: [invoice()],
        next_page_url: url.endsWith("1") ? "next" : null,
      });
    if (url.startsWith("commercial/invoices/invoice1/payments?page="))
      return json({
        invoice: invoice(),
        history: {
          data: results
            ? [
                {
                  id: "entry",
                  payment_id: "payment1",
                  entry_type: "payment",
                  receipt_reference: "R-01",
                  delta_paise: 2000,
                },
              ]
            : [],
          next_page_url: url.endsWith("1") ? "next" : null,
        },
      });
    if (url === "commercial/invoices/invoice1/payments") {
      expect(body).toEqual({ amount_paise: "2000", receipt_reference: "R-01" });
      paid = 2000;
      results++;
      return json({ id: "payment1" });
    }
    if (url === "commercial/invoices/invoice1/payments/payment1/reverse") {
      expect(body).toEqual({ reason: "Correction" });
      paid = 0;
      results++;
      return json(invoice());
    }
    throw new Error("Billing operation unavailable");
  });
  await render();
  await click("Commercial");
  const issue = "form[data-operation=billing-issue]";
  const payment = "form[data-operation=billing-payment]";
  const reverse = "form[data-operation=billing-reverse]";
  const set = (form: string, name: string, value: string) => {
    document.querySelector<HTMLInputElement>(
      form + ' input[name="' + name + '"]',
    )!.value = value;
  };
  set(issue, "subscription_id", "subscription");
  set(issue, "due_on", "2032-02-29");
  await submit(issue);
  expect(document.body.textContent).toContain("Billing record saved");
  await click("Load billing records");
  await click("Next invoices");
  await click("Previous invoices");
  await click("View invoice invoice1");
  await click("Next ledger entries");
  await click("Previous ledger entries");
  set(payment, "amount_paise", "2000");
  set(payment, "receipt_reference", "R-01");
  await submit(payment);
  expect(document.body.textContent).toContain("Partially Paid");
  set(reverse, "payment", "payment1");
  set(reverse, "reason", "Correction");
  await submit(reverse);
  expect(document.body.textContent).toContain("Payment reversal recorded");
  set(reverse, "payment", "missing");
  set(reverse, "reason", "Correction");
  await submit(reverse);
  expect(document.body.textContent).toContain("Billing operation unavailable");
  expect(results).toBe(2);
});
