export type ImportRecord = { sourceReference?: string; customer?: string; registration?: string; date?: string; amountOere?: number };
export type ImportIssue = { index: number; code: "duplicate_source" | "missing_field" | "invalid_amount"; message: string };

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
  });
  return { total: records.length, valid: records.length - new Set(issues.map((issue) => issue.index)).size, issues, writes: 0, mode: "dry_run" as const };
}
