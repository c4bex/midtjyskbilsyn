"use client";

import { BookOpen, ChevronLeft, ExternalLink, FileUp, History, Maximize2, MessageSquare, Minimize2, Plus, Save, Send, Sparkles, X } from "lucide-react";
import { FormEvent, useEffect, useRef, useState } from "react";

type Source = { document_id: number; title: string; category: string; page_number?: number | null; quotation?: string };
type Message = { id: number; role: "user" | "assistant"; content: string; confidence?: string; sources: Source[] };
type Conversation = { id: number; title: string; updated_at: string };
type DocumentItem = { id: number; title: string; category: string; publisher?: string | null; version?: string | null; status: string; processing_error?: string | null };
type Bootstrap = { status: { aiEnabled: boolean; arvoEnabled: boolean; model?: string }; conversations: Conversation[]; documents: DocumentItem[]; investigations: Array<{ id: number; reference_number: string; title: string; status: string }> };

export function AiAssistant({ open, onClose, booking }: { open: boolean; onClose: () => void; booking?: { id: string; customer: string; plate: string; date: string; time: string } | null }) {
  const [fullScreen, setFullScreen] = useState(false);
  const [tab, setTab] = useState<"chat" | "history" | "documents">("chat");
  const [bootstrap, setBootstrap] = useState<Bootstrap | null>(null);
  const [conversationId, setConversationId] = useState<number | null>(null);
  const [messages, setMessages] = useState<Message[]>([]);
  const [question, setQuestion] = useState("");
  const [includeBooking, setIncludeBooking] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [uploading, setUploading] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);
  const scrollRef = useRef<HTMLDivElement>(null);

  const loadBootstrap = async () => {
    const response = await fetch("/api/ai/bootstrap", { cache: "no-store" });
    if (!response.ok) throw new Error("AI-assistenten kunne ikke indlæses");
    setBootstrap(await response.json());
  };

  useEffect(() => {
    if (!open) return;
    setError("");
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
        body: JSON.stringify({ question: text, includeBookingContext: Boolean(includeBooking && booking), bookingId: includeBooking && booking ? Number(booking.id) : null }),
      });
      if (!response.ok) throw new Error((await response.json().catch(() => ({}))).message ?? "Spørgsmålet kunne ikke behandles");
      const answer = await response.json() as Message;
      setMessages((current) => [...current, answer]);
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
      <div><span><Sparkles size={17} /></span><div><strong>Fagassistent</strong><small>{bootstrap?.status.aiEnabled ? "AI og dokumentopslag aktivt" : "Sikkert dokumentopslag · AI ikke aktiveret"}</small></div></div>
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
        {messages.map((message) => <article key={message.id} className={`ai-message ${message.role}`}><div>{message.content}</div>{message.sources?.length > 0 && <section><strong>Kilder</strong>{message.sources.map((source, index) => <a key={`${message.id}-${source.document_id}-${index}`} href={`/api/ai/documents/${source.document_id}/file`} target="_blank" rel="noreferrer"><BookOpen size={13} /><span>{source.title}{source.page_number ? ` · side ${source.page_number}` : ""}</span><ExternalLink size={12} /></a>)}</section>}</article>)}
        {busy && <div className="ai-thinking"><i /><i /><i /><span>Undersøger kilderne…</span></div>}
      </div>
      <form className="ai-composer" onSubmit={(event) => void ask(event)}>
        {booking && <label className="ai-context"><input type="checkbox" checked={includeBooking} onChange={(event) => setIncludeBooking(event.target.checked)} /><span><strong>Medtag den valgte booking</strong><small>{booking.plate} · {booking.date} kl. {booking.time}. Kundens navn sendes ikke.</small></span></label>}
        <div><textarea value={question} onChange={(event) => setQuestion(event.target.value)} onKeyDown={(event) => { if (event.key === "Enter" && !event.shiftKey) { event.preventDefault(); void ask(); } }} placeholder="Spørg om regler, vejledning eller dokumentation…" rows={3} /><button type="submit" disabled={!question.trim() || busy} aria-label="Send spørgsmål"><Send size={18} /></button></div>
        <small>Kontrollér altid kilde og gyldighed før en afgørelse.</small>
      </form>
    </>}
    {tab === "history" && <div className="ai-library"><header><div><h2>Samtaler og undersøgelser</h2><p>Fortsæt tidligere arbejde uden at starte forfra.</p></div></header><h3>Samtaler</h3>{bootstrap?.conversations.map((item) => <button className="ai-history-row" key={item.id} onClick={() => void openConversation(item.id)}><MessageSquare size={16} /><span><strong>{item.title}</strong><small>{new Date(item.updated_at).toLocaleString("da-DK")}</small></span><ChevronLeft className="reverse" size={15} /></button>)}<h3>Gemte undersøgelser</h3>{bootstrap?.investigations.map((item) => <article className="ai-investigation" key={item.id}><span>{item.reference_number}</span><strong>{item.title}</strong><small>{item.status} · ARVO-overførsel er deaktiveret</small></article>)}</div>}
    {tab === "documents" && <div className="ai-library"><header><div><h2>Dokumentbibliotek</h2><p>PDF-filer gemmes privat og bruges som kildegrundlag.</p></div><button onClick={() => fileRef.current?.click()}><FileUp size={15} />Upload</button></header><form className="ai-upload-form" onSubmit={(event) => void uploadDocument(event)}><input name="title" required placeholder="Dokumentets titel" /><select name="category" required defaultValue="Vejledning"><option>Lovgivning</option><option>Bekendtgørelse</option><option>Vejledning</option><option>Intern procedure</option><option>Tidligere sag</option></select><input name="publisher" placeholder="Udgiver (valgfri)" /><input ref={fileRef} name="file" required type="file" accept=".pdf,.txt,.md,.csv" /><button disabled={uploading} type="submit">{uploading ? "Behandler…" : "Gem dokument"}</button></form><div className="ai-documents">{bootstrap?.documents.map((document) => <article key={document.id}><span className={`document-status ${document.status}`}>{document.status === "ready" ? "Klar" : document.status === "error" ? "Fejl" : "Behandler"}</span><div><strong>{document.title}</strong><small>{document.category}{document.publisher ? ` · ${document.publisher}` : ""}{document.version ? ` · ${document.version}` : ""}</small>{document.processing_error && <em>{document.processing_error}</em>}</div>{document.status === "ready" && <a href={`/api/ai/documents/${document.id}/file`} target="_blank" rel="noreferrer"><ExternalLink size={15} /></a>}</article>)}</div></div>}
  </div>;
}
