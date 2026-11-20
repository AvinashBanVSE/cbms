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

it("configures the portal, creates its owner, signs in, manages a service and signs out", async () => {
  let page = 1;
  let saved = false;
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
      case "services/categories":
        return json({ B1: "Communications" });
      case "services":
        expect(body?.name).toBe("Messaging");
        saved = true;
        return json({ id: "first" });
      case "services/first/capabilities":
        expect(body?.name).toBe("Email");
        return json({ id: "child" });
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
    if (path === "services/categories")
      throw new PortalRequestError("Access denied", 403);
    if (path === "mapping-contexts")
      return json(
        ["initiate", "verify", "confirm", "end"].map((action) => ({
          id: action === "verify" ? "distributor" : "shop",
          professional_name: "My business",
          action: "mapping." + action,
        })),
      );
    if (path === "shops/shop/mappings")
      return json({ id: "m1", code: "shop-code" });
    if (path.endsWith("/verify"))
      return json({ id: "m1", code: "distributor-code" });
    if (path.endsWith("/confirm")) return json({ state: "Active" });
    if (path.endsWith("/end")) throw new Error("Mapping already ended");
    if (path === "logout") return new Response(null, { status: 204 });
    throw Error(path);
  });
  await render();
  input("party", "distributor");
  await submit();
  expect(document.body.textContent).toContain("shop-code");
  const selection =
    document.querySelector<HTMLSelectElement>("section select")!;
  async function select(value: string) {
    await act(async () => {
      selection.value = value;
      selection.dispatchEvent(new Event("change", { bubbles: true }));
    });
    input("mapping_id", "m1");
  }
  await select("1");
  input("party", "shop");
  input("code", "shop-code");
  await submit();
  expect(request).toHaveBeenCalledWith(
    "distributors/distributor/mappings/m1/verify",
    { shop_id: "shop", code: "shop-code" },
  );
  await select("2");
  input("party", "distributor");
  input("code", "distributor-code");
  await submit();
  expect(document.body.textContent).toContain("Mapping status: Active");
  await select("3");
  await submit();
  expect(document.body.textContent).toContain("Mapping already ended");
  await click("Sign out");
  expect(document.querySelector("h1")?.textContent).toBe("Sign in");
});

it("configures account setup and provisions an authorized Organization user", async () => {
  request.mockImplementation(async (path, body) => {
    switch (path) {
      case "installation":
        return json({ stage: "ready" });
      case "services/categories":
        return json({ A1: "Identity" });
      case "services?page=1":
        return json({ data: [] });
      case "tenants/tenant/credential-policy":
        return json(body);
      case "tenants/tenant/organization-users":
        return json({ user_code: "AB1234", delivery: "failed" });
      case "tenants/tenant/organization-users/reissue":
        if (body?.code === "AB1235") throw new Error("User unavailable");
        return json({ delivery: "sent" });
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
});

it("sets a permanent password through the separate restricted setup screen", async () => {
  request.mockImplementation(async (path, body) => {
    if (path === "installation") return json({ stage: "ready" });
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
