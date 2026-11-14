# ReplyTonic — Onboarding Email Sequence
# 5 emails · Load into MailerLite as an automation triggered on signup

---

## Email 1 — Welcome (send immediately on signup)

**Subject:** You're in — here's your first free tool 🎉
**Preview text:** 5 free uses per day. 7 tools. Start with the one that saves you the most time.

---

Hi {{subscriber.name | default: "there"}},

Welcome to ReplyTonic. You just unlocked 7 AI-powered tools that make every message you send better.

**Start here — the Rewrite tool:**

Paste any message you've been staring at. Get 3 polished versions in 2 seconds.

→ [Open ReplyTonic](https://replytonic.com)

**Your free plan includes:**
- ✏️ Rewrite — 3 tone alternatives instantly
- 💬 Reply Generator — perfect responses to anything
- 📋 Summarize — tl;dr any long message
- ✅ Grammar Check — fix before you send
- 🤖 Humanize AI Text — make AI drafts sound human
- 🔍 AI Content Detector — know if something was AI-written
- 📄 Plagiarism Checker — originality score before you publish

You get **5 free uses per day** across all 7 tools. No credit card needed.

See you on the other side,
— The ReplyTonic Team

P.S. Got the Chrome Extension yet? It puts all 7 tools inside Gmail, LinkedIn, Twitter, and 20+ other platforms. [Add it free →](https://chrome.google.com/webstore/detail/replytonic)

---

## Email 2 — Tip: The Reply Generator (send Day 2)

**Subject:** The fastest way to reply to a difficult message
**Preview text:** Paste what they sent you. Pick a tone. Done in 3 seconds.

---

Hi {{subscriber.name | default: "there"}},

Ever stare at a message and not know what to say?

That's exactly what the **Reply Generator** is built for.

**How it works:**
1. Paste the message you received
2. Pick a tone (Professional, Casual, Direct, Friendly…)
3. Get a ready-to-send reply in 3 seconds

It works for:
- Tough emails from your boss
- Client messages where tone matters
- LinkedIn DMs you want to handle professionally
- Awkward texts you don't know how to answer

→ [Try the Reply Generator](https://replytonic.com)

**Pro tip:** Use "Direct" tone when you need to say no. Use "Empathetic" when the conversation is sensitive.

— The ReplyTonic Team

---

## Email 3 — Tip: Chrome Extension (send Day 4)

**Subject:** Stop switching tabs — use ReplyTonic inside Gmail
**Preview text:** One click. 7 tools. Right inside the page you're already on.

---

Hi {{subscriber.name | default: "there"}},

Here's how most people use ReplyTonic: they switch back and forth between the app and their email or LinkedIn.

There's a faster way.

**The ReplyTonic Chrome Extension** puts all 7 tools directly inside any website:

- Inside Gmail — rewrite or reply without leaving your inbox
- Inside LinkedIn — craft messages that don't sound templated
- Inside Twitter/X — write better before you post
- Inside Slack, Notion, WhatsApp Web, and 20+ more

**It takes 30 seconds to set up:**

→ [Add ReplyTonic to Chrome — Free](https://chrome.google.com/webstore/detail/replytonic)

Then just click the ReplyTonic button that appears on any page.

— The ReplyTonic Team

---

## Email 4 — Feature Spotlight: Humanize + AI Detector (send Day 7)

**Subject:** Is your writing flagging as AI? Here's the fix.
**Preview text:** Two tools that work together — Humanize AI Text + AI Content Detector.

---

Hi {{subscriber.name | default: "there"}},

If you use AI to draft anything — emails, LinkedIn posts, essays, blog content — you've probably wondered:
*Does this read as AI-written?*

ReplyTonic has two tools that work together to solve this:

**🔍 AI Content Detector**
Paste any text. Get a probability score, flagged phrases, and confidence level. Know before you send.

**🤖 Humanize AI Text**
Take AI-drafted content and rewrite it so it sounds like a real person wrote it. Passes detection. Keeps the meaning.

**The workflow:**
1. Write your draft (AI or not)
2. Run it through AI Content Detector
3. If the score is high, run Humanize
4. Check the score again — usually drops dramatically

→ [Try it now — both are on your free plan](https://replytonic.com)

— The ReplyTonic Team

---

## Email 5 — Upgrade prompt (send Day 10)

**Subject:** You've used ReplyTonic {{subscriber.fields.usage_count | default: "a few times"}} — ready to go unlimited?
**Preview text:** No daily limits. All 7 tools. Chrome Extension. $12/month.

---

Hi {{subscriber.name | default: "there"}},

You've been getting value from ReplyTonic — and you're probably hitting the 5-per-day limit.

Here's what **ReplyTonic Premium** unlocks:

✅ Unlimited uses across all 7 tools
✅ Priority AI processing (faster results)
✅ Full rewrite history (access past results anytime)
✅ Chrome Extension with all tools
✅ Email support

**$12/month — cancel anytime.**

If you write 10+ messages a day, that's literally $1.20 per day to never send a bad message again.

→ [Upgrade to Premium](https://replytonic.com/#pricing)

Still on the fence? Reply to this email and tell me what tool you use most. I'll make sure you're getting the most out of it.

— The ReplyTonic Team

P.S. Teams plan is $49/month for up to 10 users. If your team communicates at scale, [let's talk](mailto:hello@replytonic.com).

---

# MailerLite Setup Instructions

1. Go to **Automations** → Create new automation
2. Trigger: **When subscriber joins a group** → group: "ReplyTonic Free Signups"
3. Add each email as a **Send email** step
4. Delays between steps:
   - Email 1: Send immediately
   - Email 2: Wait 1 day
   - Email 3: Wait 2 days (from Email 2)
   - Email 4: Wait 3 days (from Email 3)
   - Email 5: Wait 3 days (from Email 4)
5. From name: **ReplyTonic**
6. From email: hello@replytonic.com (or your verified sender)

# Trigger from PHP signup
In `api/register.php`, after successful user creation, add:
```php
// Add to MailerLite free signups group via API
// (Add MailerLite API call here with group ID for "ReplyTonic Free Signups")
```
