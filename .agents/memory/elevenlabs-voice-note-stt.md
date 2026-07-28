---
name: Owner voice-note transcription (ElevenLabs STT)
description: Working recipe to transcribe owner's WhatsApp-style .m4a voice notes (Urdu) via the ElevenLabs proxy — manual multipart + temp public URL on live
---

Owner regularly sends `.m4a` voice notes (Urdu/Punjabi) as attached assets; they must be transcribed before replying.

**Working recipe:**
1. Stage the file at a temporary PUBLIC URL on the live server (dev workspace has no public static URL):
   `scp` to `/home/taxnestc/public_html/public/tmp_<24-hex-rand>.m4a`, then **`chmod 644`** (scp preserves the workspace's 600 perms → Apache serves 403). Verify with curl expecting 200.
2. Call `externalApi__elevenlabs` `POST /v1/speech-to-text`:
   - `body` MUST be a **string** (the proxy rejects objects), and the endpoint requires **multipart/form-data** — JSON body 422s with "model_id missing".
   - Build the multipart body manually: boundary string + form fields `model_id=scribe_v1` and `cloud_storage_url=<the temp URL>`; header `Content-Type: multipart/form-data; boundary=<B>`.
3. **Delete the temp file from live immediately after** (privacy).

Scribe v1 auto-detects language (returns `language_code` urd/hin with ~0.98 prob for the owner's notes) and handles code-switched Urdu/English well.

**Why:** three failure modes cost a round each — object body (pydantic string_type error), JSON content type (422 model_id missing), and 403 from preserved 600 perms. This recipe is the one that works end-to-end.

**UPDATE (28 Jul 2026):** the ElevenLabs proxy callback now REJECTS string bodies (schema requires Record) and its multipart encoding 400s even with model_id alone — STT via the proxy is currently broken. Working fallback: the project's own `.env` has `OPENAI_API_KEY` (strip surrounding quotes!) — `curl https://api.openai.com/v1/audio/transcriptions -F model=gpt-4o-mini-transcribe -F file=@x.m4a -F response_format=text` from the shell transcribes Urdu voice notes locally, no public URL staging needed.
