"use client";

import { AlertTriangle, BookOpen, CheckCircle2, ChevronLeft, ExternalLink, FilePenLine, FileUp, Globe2, History, Maximize2, MessageSquare, Minimize2, Plus, RefreshCw, Save, Send, ShieldCheck, Sparkles, X } from "lucide-react";
import { FormEvent, useEffect, useRef, useState } from "react";

type Source = { kind?: "document" | "web"; document_id?: number; title: string; category?: string; page_number?: number | null; quotation?: string; url?: string; domain?: string };
type Message = { id: number; role: "user" | "assistant"; content: string; confidence?: string; warnings?: string[]; sources: Source[] };
type Conversation = { id: number; title: string; updated_at: string };
type DocumentItem = { id: number; title: string; description?: string | null; category: string; publisher?: string | null; version?: string | null; valid_from?: string | null; valid_to?: string | null; status: string; approval_status: "draft" | "approved" | "rejected" | "archived" | "superseded"; extraction_method?: string | null; ocr_attempted?: boolean; review_notes?: string | null; processing_error?: string | null; is_active: boolean };
type Bootstrap = { status: { aiEnabled: boolean; testMode: boolean; arvoEnabled: boolean; webSearchAvailable: boolean; webAllowedDomains: string[]; model?: string; activationChecks: { apiKeyConfigured: boolean; aiFeatureEnabled: boolean; approvedDocuments: number; webDomainsConfigured: boolean } }; conversations: Conversation[]; documents: DocumentItem[]; investigations: Array<{ id: number; reference_number: string; title: string; status: string }> };

export function AiAssistant({ open, onClose, booking }: { open: boolean; onClose: () => void; booking?: { id: string; customer: string; plate: string; date: string; time: string } | null }) {
  const [fullScreen, setFullScreen] = useState(false);
  const [tab, setTab] = useState<"chat" | "history" | "documents">("chat");
  const [bootstrap, setBootstrap] = useState<Bootstrap | null>(null);
  const [conversationId, setConversationId] = useState<number | null>(null);
  const [messages, setMessages] = useState<Message[]>([]);
  const [question, setQuestion] = useState("");
  const [includeBooking, setIncludeBooking] = useState(false);
  const [useWebSearch, setUseWebSearch] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [uploading, setUploading] = useState(false);
  const [editingDocument, setEditingDocument] = useState<number | null>(null);
  const [documentBusy, setDocumentBusy] = useState<number | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);
  const scrollRef = useRef<HTMLDivElement>(null);

  const loadBootstrap = async () => {
    setError("");
    const response = await fetch("/api/ai/bootstrap", { cache: "no-store" });
    if (!response.ok) throw new Error("AI-assistenten kunne ikke indlæses");
    setBootstrap(await response.json());
  };

  useEffect(() => {
    if (!open) return;
    // Loading the assistant is an external synchronization; errors arrive asynchronously.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void loadBootstrap().catch((value) => setError(value instanceof Error ? value.message : "Assistenten kunne ikke åbnes"));
  }, [open]);
  useEffect(() => {
    const close = (event: KeyboardEvent) => { if (event.key === "Escape" && open) onClose(); };
    window.addEventListener("keydown", close);
    return () => window.removeEventListener("keydown", close);
  }, [open, onClose]);
  useEffect(() => { scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: "smooth" }); }, [messages, busy]);

  const newConversation = async (resetMessages = true) => {
    const response = await fetch("/api/ai/conversations", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({}) });
    if (!response.ok) throw new Error("Samtalen kunne ikke oprettes");
    const data = await response.json();
    setConversationId(data.conversation.id); if (resetMessages) setMessages([]); setTab("chat");
    return data.conversation.id as number;
  };

  const openConversation = async (id: number) => {
    setBusy(true); setError("");
    try {
      const response = await fetch(`/api/ai/conversations/${id}`, { cache: "no-store" });
      if (!response.ok) throw new Error("Samtalen kunne ikke hentes");
      const data = await response.json();
      setConversationId(id); setMessages(data.messages); setTab("chat");
    } catch (value) { setError(value instanceof Error ? value.message : "Samtalen kunne ikke hentes"); }
    finally { setBusy(false); }
  };

  const ask = async (event?: FormEvent) => {
    event?.preventDefault();
    const text = question.trim();
    if (!text || busy) return;
    setBusy(true); setError(""); setQuestion("");
    setMessages((current) => [...current, { id: -Date.now(), role: "user", content: text, sources: [] }]);
    try {
      const id = conversationId ?? await newConversation(false);
      const response = await fetch(`/api/ai/conversations/${id}/messages`, {
        method: "POST", headers: { "content-type": "application/json" },
        body: JSON.stringify({ question: text, includeBookingContext: Boolean(includeBooking && booking), bookingId: includeBooking && booking ? Number(booking.id) : null, useWebSearch }),
      });
      if (!response.ok) throw new Error((await response.json().catch(() => ({}))).message ?? "Spørgsmålet kunne ikke behandles");
      const answer = await response.json() as Message;
      setMessages((current) => [...current, answer]);
      setUseWebSearch(false);
      await loadBootstrap();
    } catch (value) { setError(value instanceof Error ? value.message : "Assistenten kunne ikke svare"); }
    finally { setBusy(false); }
  };

  const uploadDocument = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    setUploading(true); setError("");
    try {
      const response = await fetch("/api/ai/documents", { method: "POST", body: data });
      if (!response.ok) { const body = await response.json().catch(() => ({})); throw new Error(body.message ?? "Dokumentet kunne ikke uploades"); }
      form.reset(); await loadBootstrap();
    } catch (value) { setError(value instanceof Error ? value.message : "Dokumentet kunne ikke uploades"); }
    finally { setUploading(false); }
  };

  const updateDocument = async (event: FormEvent<HTMLFormElement>, id: number) => {
    event.preventDefault();
    const form = event.currentTarget;
    const values = Object.fromEntries(new FormData(form).entries());
    setDocumentBusy(id); setError("");
    try {
      const response = await fetch(`/api/ai/documents/${id}`, { method: "PATCH", headers: { "content-type": "application/json" }, body: JSON.stringify(values) });
      if (!response.ok) throw new Error((await response.json().catch(() => ({}))).message ?? "Dokumentet kunne ikke opdateres");
      setEditingDocument(null); await loadBootstrap();
    } catch (value) { setError(value instanceof Error ? value.message : "Dokumentet kunne ikke opdateres"); }
    finally { setDocumentBusy(null); }
  };

  const reprocessDocument = async (id: number) => {
    setDocumentBusy(id); setError("");
    try {
      const response = await fetch(`/api/ai/documents/${id}/reprocess`, { method: "POST" });
      if (!response.ok) throw new Error("Dokumentet kunne ikke behandles igen");
      await loadBootstrap();
    } catch (value) { setError(value instanceof Error ? value.message : "Dokumentet kunne ikke behandles igen"); }
    finally { setDocumentBusy(null); }
  };

  const askFollowUp = (message: Message) => {
    setQuestion(`Uddyb dette svar og forklar, hvad vi konkret skal gøre:\n\n${message.content.slice(0, 500)}`);
    setTab("chat");
  };

  const saveInvestigation = async () => {
    const lastAnswer = [...messages].reverse().find((message) => message.role === "assistant");
    if (!lastAnswer) return;
    setBusy(true);
    try {
      const response = await fetch("/api/ai/investigations", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ title: bootstrap?.conversations.find((item) => item.id === conversationId)?.title ?? "Undersøgelse fra AI-assistent", description: lastAnswer.content, conversationId, bookingId: includeBooking && booking ? Number(booking.id) : null }) });
      if (!response.ok) throw new Error("Undersøgelsen kunne ikke gemmes");
      await loadBootstrap(); setError("Undersøgelsen er gemt i historikken.");
    } catch (value) { setError(value instanceof Error ? value.message : "Undersøgelsen kunne ikke gemmes"); }
    finally { setBusy(false); }
  };

  if (!open) return null;
  return <div className={`ai-assistant ${fullScreen ? "fullscreen" : ""}`} role="dialog" aria-modal="false" aria-label="AI-assistent">
    <header className="ai-assistant-head">
      <div><span><Sparkles size={17} /></span><div><strong>Fagassistent</strong><small>{bootstrap?.status.aiEnabled ? bootstrap.status.testMode ? "Kontrolleret AI-test · ingen bookingdata" : "AI og dokumentopslag aktivt" : "Sikkert dokumentopslag · AI ikke aktiveret"}</small></div></div>
      <nav><button aria-label={fullScreen ? "Vis som sidepanel" : "Vis i fuld skærm"} onClick={() => setFullScreen((value) => !value)}>{fullScreen ? <Minimize2 size={18} /> : <Maximize2 size={18} />}</button><button aria-label="Luk assistent" onClick={onClose}><X size={20} /></button></nav>
    </header>
    <div className="ai-assistant-tabs">
      <button className={tab === "chat" ? "active" : ""} onClick={() => setTab("chat")}><MessageSquare size={15} />Spørg</button>
      <button className={tab === "history" ? "active" : ""} onClick={() => setTab("history")}><History size={15} />Historik</button>
      <button className={tab === "documents" ? "active" : ""} onClick={() => setTab("documents")}><BookOpen size={15} />Dokumenter</button>
    </div>
    {error && <div className={`ai-assistant-notice ${error.includes("gemt") ? "success" : ""}`}>{error}</div>}
    {tab === "chat" && <>
      <div className="ai-chat" ref={scrollRef}>
        <div className="ai-chat-toolbar"><button onClick={() => void newConversation().catch(() => setError("Ny samtale kunne ikke oprettes"))}><Plus size={15} />Ny samtale</button>{conversationId && messages.some((item) => item.role === "assistant") && <button onClick={() => void saveInvestigation()}><Save size={15} />Gem undersøgelse</button>}</div>
        {messages.length === 0 && <section className="ai-welcome"><span><Sparkles size={22} /></span><h2>Hvad skal vi undersøge?</h2><p>Spørg til regler, vejledninger eller tidligere dokumentation. Svaret viser altid de anvendte kilder.</p><div><button onClick={() => setQuestion("Hvilke regler gælder for denne type syn?")}>Regler for syn</button><button onClick={() => setQuestion("Find relevant vejledning om registrering")}>Registrering</button></div></section>}
        {messages.map((message) => <article key={message.id} className={`ai-message ${message.role}`}><div>{message.content}</div>{message.warnings?.map((warning) => <p className="ai-source-warning" key={warning}><AlertTriangle size={13} />{warning}</p>)}{message.sources?.length > 0 && <section><strong>Kilder</strong>{message.sources.map((source, index) => {
          const web = source.kind === "web";
          const href = web ? source.url : `/api/ai/documents/${source.document_id}/file`;
          return <a className={web ? "web-source" : ""} key={`${message.id}-${source.document_id ?? source.url}-${index}`} href={href} target="_blank" rel="noreferrer">{web ? <Globe2 size={13} /> : <BookOpen size={13} />}<span><b>{web ? "Officiel webkilde" : "Virksomhedens dokument"}</b>{source.title}{web && source.domain ? ` · ${source.domain}` : source.page_number ? ` · side ${source.page_number}` : ""}</span><ExternalLink size={12} /></a>;
        })}</section>}{message.role === "assistant" && <footer><button onClick={() => askFollowUp(message)}><MessageSquare size={13} />Spørg videre</button><button onClick={() => void saveInvestigation()}><Save size={13} />Gem undersøgelse</button></footer>}</article>)}
        {busy && <div className="ai-thinking"><i /><i /><i /><span>Undersøger kilderne…</span></div>}
      </div>
      <form className="ai-composer" onSubmit={(event) => void ask(event)}>
        {booking && <label className="ai-context"><input type="checkbox" checked={includeBooking} onChange={(event) => setIncludeBooking(event.target.checked)} /><span><strong>Medtag den valgte booking</strong><small>{booking.plate} · {booking.date} kl. {booking.time}. Kundens navn sendes ikke.</small></span></label>}
        <label className={`ai-web-option ${bootstrap?.status.webSearchAvailable ? "" : "disabled"}`}><input type="checkbox" checked={useWebSearch} disabled={!bootstrap?.status.webSearchAvailable} onChange={(event) => setUseWebSearch(event.target.checked)} /><Globe2 size={16} /><span><strong>Søg også i officielle kilder</strong><small>{bootstrap?.status.webSearchAvailable ? "Kun godkendte myndighedssider · valget gælder ét spørgsmål" : "Klargjort, men ikke aktiveret på serveren endnu"}</small></span>{bootstrap?.status.webSearchAvailable && <ShieldCheck size={15} />}</label>
        <div><textarea value={question} onChange={(event) => setQuestion(event.target.value)} onKeyDown={(event) => { if (event.key === "Enter" && !event.shiftKey) { event.preventDefault(); void ask(); } }} placeholder="Spørg om regler, vejledning eller dokumentation…" rows={3} /><button type="submit" disabled={!question.trim() || busy} aria-label="Send spørgsmål"><Send size={18} /></button></div>
        <small>Kontrollér altid kilde og gyldighed før en afgørelse.</small>
      </form>
    </>}
    {tab === "history" && <div className="ai-library"><header><div><h2>Samtaler og undersøgelser</h2><p>Fortsæt tidligere arbejde uden at starte forfra.</p></div></header><h3>Samtaler</h3>{bootstrap?.conversations.map((item) => <button className="ai-history-row" key={item.id} onClick={() => void openConversation(item.id)}><MessageSquare size={16} /><span><strong>{item.title}</strong><small>{new Date(item.updated_at).toLocaleString("da-DK")}</small></span><ChevronLeft className="reverse" size={15} /></button>)}<h3>Gemte undersøgelser</h3>{bootstrap?.investigations.map((item) => <article className="ai-investigation" key={item.id}><span>{item.reference_number}</span><strong>{item.title}</strong><small>{item.status} · ARVO-overførsel er deaktiveret</small></article>)}</div>}
    {tab === "documents" && <div className="ai-library"><header><div><h2>Dokumentbibliotek</h2><p>Kun godkendte og gyldige dokumenter bruges som svargrundlag.</p></div><button onClick={() => fileRef.current?.click()}><FileUp size={15} />Upload</button></header>
      {bootstrap && <section className="ai-readiness"><div><ShieldCheck size={18} /><span><strong>Klar til sikker AI-test</strong><small>{bootstrap.status.activationChecks.approvedDocuments} godkendte dokumenter · API-nøgle {bootstrap.status.activationChecks.apiKeyConfigured ? "registreret" : "mangler"}</small></span></div><ul><li className={bootstrap.status.activationChecks.approvedDocuments > 0 ? "ok" : ""}>Godkendt kildegrundlag</li><li className={bootstrap.status.activationChecks.webDomainsConfigured ? "ok" : ""}>Officielle domæner</li><li className={bootstrap.status.activationChecks.aiFeatureEnabled ? "ok" : ""}>AI-test aktiveret</li></ul></section>}
      <form className="ai-upload-form" onSubmit={(event) => void uploadDocument(event)}><input name="title" required placeholder="Dokumentets titel" /><select name="category" required defaultValue="Vejledning"><option>Lovgivning</option><option>Bekendtgørelse</option><option>Vejledning</option><option>Intern procedure</option><option>Tidligere sag</option></select><input name="publisher" placeholder="Udgiver (valgfri)" /><input name="version" placeholder="Version (f.eks. 2026-1)" /><label>Gyldig fra<input name="valid_from" type="date" /></label><label>Gyldig til<input name="valid_to" type="date" /></label><select name="replaces_document_id" defaultValue=""><option value="">Nyt dokument</option>{bootstrap?.documents.filter((item) => item.is_active).map((item) => <option value={item.id} key={item.id}>Erstatter: {item.title}</option>)}</select><input ref={fileRef} name="file" required type="file" accept=".pdf,.txt,.md,.csv" /><button disabled={uploading} type="submit">{uploading ? "Behandler…" : "Gem som kladde"}</button></form>
      <div className="ai-documents">{bootstrap?.documents.map((document) => <article className={!document.is_active ? "inactive" : ""} key={document.id}><div className="document-summary"><span className={`document-status ${document.status}`}>{document.status === "ready" ? "Læst" : document.status === "error" ? "Fejl" : "Behandler"}</span><span className={`approval-status ${document.approval_status}`}>{document.approval_status === "approved" ? "Godkendt" : document.approval_status === "draft" ? "Kladde" : document.approval_status === "superseded" ? "Erstattet" : document.approval_status === "archived" ? "Arkiveret" : "Afvist"}</span><div><strong>{document.title}</strong><small>{document.category}{document.publisher ? ` · ${document.publisher}` : ""}{document.version ? ` · ${document.version}` : ""}</small><small>{document.extraction_method === "ocr" ? "Læst med OCR" : document.extraction_method ? "Tekst udtrukket automatisk" : "Afventer tekstbehandling"}{document.valid_to ? ` · gyldig til ${new Date(document.valid_to).toLocaleDateString("da-DK")}` : ""}</small>{document.processing_error && <em>{document.processing_error}</em>}</div><nav><button aria-label={`Rediger ${document.title}`} onClick={() => setEditingDocument(editingDocument === document.id ? null : document.id)}><FilePenLine size={15} /></button><button aria-label={`Behandl ${document.title} igen`} disabled={documentBusy === document.id} onClick={() => void reprocessDocument(document.id)}><RefreshCw size={15} /></button>{document.status === "ready" && <a aria-label={`Åbn ${document.title}`} href={`/api/ai/documents/${document.id}/file`} target="_blank" rel="noreferrer"><ExternalLink size={15} /></a>}</nav></div>
        {editingDocument === document.id && <form className="ai-document-edit" onSubmit={(event) => void updateDocument(event, document.id)}><input name="title" required defaultValue={document.title} /><select name="category" required defaultValue={document.category}><option>Lovgivning</option><option>Bekendtgørelse</option><option>Vejledning</option><option>Intern procedure</option><option>Tidligere sag</option></select><input name="publisher" defaultValue={document.publisher ?? ""} placeholder="Udgiver" /><input name="version" defaultValue={document.version ?? ""} placeholder="Version" /><label>Gyldig fra<input name="valid_from" type="date" defaultValue={document.valid_from?.slice(0, 10) ?? ""} /></label><label>Gyldig til<input name="valid_to" type="date" defaultValue={document.valid_to?.slice(0, 10) ?? ""} /></label><select name="approval_status" defaultValue={document.approval_status}><option value="draft">Kladde</option><option value="approved">Godkendt</option><option value="rejected">Afvist</option><option value="archived">Arkiveret</option></select><textarea name="review_notes" defaultValue={document.review_notes ?? ""} placeholder="Intern note om godkendelsen" /><button disabled={documentBusy === document.id} type="submit"><CheckCircle2 size={14} />Gem ændringer</button></form>}
      </article>)}</div></div>}
  </div>;
}
