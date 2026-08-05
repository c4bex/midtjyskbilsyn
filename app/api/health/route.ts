import { ensureBookingDatabase } from "../../../db/bootstrap";
import { getD1 } from "../../../db";
import { dmrAdapter } from "../../../lib/integrations/adapters/dmr";
import { gatewayApiAdapter } from "../../../lib/integrations/adapters/gatewayapi";
import { dineroAdapter } from "../../../lib/integrations/adapters/dinero";
import { synsprogramAdapter } from "../../../lib/integrations/adapters/synsprogram";

export async function GET() {
  const checkedAt = new Date().toISOString();
  try {
    await ensureBookingDatabase();
    await getD1().prepare("SELECT 1").first();
    return Response.json({ status: "ok", checkedAt, database: "ok", integrations: { dmr: dmrAdapter.enabled, gatewayapi: gatewayApiAdapter.enabled, dinero: dineroAdapter.enabled, synsprogram: synsprogramAdapter.enabled } });
  } catch {
    return Response.json({ status: "degraded", checkedAt, database: "unavailable" }, { status: 503 });
  }
}
