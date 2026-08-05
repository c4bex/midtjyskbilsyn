export type ImportRecord = { sourceReference?: string; customer?: string; registration?: string; date?: string; amountOere?: number };
export type ImportIssue = { index: number; code: "duplicate_source" | "missing_field" | "invalid_amount" | "invalid_date" | "invalid_registration"; message: string };

export function validateImport(records: ImportRecord[]) {
  const issues: ImportIssue[] = [];
  const seen = new Map<string, number>();
  records.forEach((record, index) => {
    if (!record.sourceReference || !record.customer || !record.registration || !record.date) issues.push({ index, code: "missing_field", message: "Kilde, kunde, registrering og dato skal udfyldes" });
    if (record.sourceReference) {
      const previous = seen.get(record.sourceReference);
      if (previous !== undefined) issues.push({ index, code: "duplicate_source", message: `Samme kilde-reference findes allerede på linje ${previous + 1}` });
      else seen.set(record.sourceReference, index);
    }
    if (record.amountOere !== undefined && (!Number.isInteger(record.amountOere) || record.amountOere < 0)) issues.push({ index, code: "invalid_amount", message: "Beløbet skal være et positivt heltalsbeløb i øre" });
    if (record.date && !/^\d{4}-\d{2}-\d{2}$/.test(record.date)) issues.push({ index, code: "invalid_date", message: "Dato skal være i formatet ÅÅÅÅ-MM-DD" });
    if (record.registration && !/^[A-ZÆØÅ0-9 -]{5,10}$/i.test(record.registration)) issues.push({ index, code: "invalid_registration", message: "Registreringsnummeret har et ugyldigt format" });
  });
  return { total: records.length, valid: records.length - new Set(issues.map((issue) => issue.index)).size, issues, writes: 0, mode: "dry_run" as const };
}
