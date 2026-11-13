import OpenAI from 'openai';

const openai = new OpenAI({ apiKey: process.env.OPENAI_API_KEY });

const TONE_DESCRIPTIONS = {
  Professional: 'professional and business-appropriate',
  Formal:       'formal, polished, and highly professional',
  Friendly:     'warm, friendly, and approachable',
  Assertive:    'confident, direct, and assertive',
  Empathetic:   'empathetic, understanding, and emotionally intelligent',
  Witty:        'clever, witty, and lightly humorous',
  Direct:       'clear, concise, and straight to the point',
  Casual:       'casual and conversational',
  Apologetic:   'apologetic, sincere, and solution-focused',
};

const PLATFORM_CONTEXT = {
  Email:     'professional email',
  WhatsApp:  'WhatsApp message',
  LinkedIn:  'LinkedIn professional message',
  SMS:       'short SMS text message',
  Slack:     'Slack workplace message',
  Text:      'casual text message',
  Facebook:  'Facebook message',
};

export default async function handler(req, res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

  if (req.method === 'OPTIONS') return res.status(200).end();
  if (req.method !== 'POST') return res.status(405).json({ error: 'Method not allowed' });

  const {
    message,
    originalMessage,
    platform = 'Email',
    tone = 'Professional',
    tone2,
    language = 'English',
    length = 'Same',
    emoji = false,
    mode = 'rewrite',
  } = req.body || {};

  if (!message || !message.trim()) {
    return res.status(400).json({ error: 'Message is required' });
  }

  const toneDesc  = TONE_DESCRIPTIONS[tone]  || tone.toLowerCase();
  const tone2Desc = tone2 && tone2 !== 'None' ? TONE_DESCRIPTIONS[tone2] || tone2.toLowerCase() : null;
  const platCtx   = PLATFORM_CONTEXT[platform] || platform;

  const blendedToneDesc = tone2Desc
    ? `a blend of ${toneDesc} and ${tone2Desc}`
    : toneDesc;

  const lengthInstruction =
    length === 'Shorter' ? 'Each version should be noticeably shorter than the input.' :
    length === 'Longer'  ? 'Each version should be more detailed and longer than the input.' :
                           'Keep each version roughly the same length as the input.';

  const emojiInstruction = emoji
    ? 'Naturally weave in 1–2 relevant emojis per version.'
    : 'Do not use any emojis.';

  const languageInstruction = language && language !== 'English'
    ? `Write all 3 versions in ${language}.`
    : 'Write in English.';

  let userPrompt;

  if (mode === 'reply') {
    userPrompt =
      `A user received this message:\n"${originalMessage || ''}"\n\n` +
      `They drafted this reply:\n"${message}"\n\n` +
      `Generate 3 distinct ${blendedToneDesc} reply versions for a ${platCtx}. ` +
      `${lengthInstruction} ${emojiInstruction} ${languageInstruction}`;
  } else if (mode === 'followup') {
    userPrompt =
      `Original message sent:\n"${originalMessage || message}"\n\n` +
      `Generate 3 distinct ${blendedToneDesc} follow-up message versions for a ${platCtx}. ` +
      `Each follow-up should feel natural, reference the original message, and prompt a response. ` +
      `${lengthInstruction} ${emojiInstruction} ${languageInstruction}`;
  } else {
    // rewrite (default)
    userPrompt =
      `Rewrite this message in 3 distinct ${blendedToneDesc} versions for a ${platCtx}:\n\n"${message}"\n\n` +
      `Each version should sound different from the others while conveying the same core meaning. ` +
      `${lengthInstruction} ${emojiInstruction} ${languageInstruction}`;
  }

  userPrompt += '\n\nRespond ONLY with valid JSON in this exact shape: {"v1":"...","v2":"...","v3":"..."}';

  try {
    const completion = await openai.chat.completions.create({
      model: 'gpt-4o',
      messages: [
        {
          role: 'system',
          content:
            'You are ReplyTonic, an expert AI message rewriter. ' +
            'You produce polished, context-aware message rewrites that feel natural and human. ' +
            'Always return exactly the JSON format requested — nothing else.',
        },
        { role: 'user', content: userPrompt },
      ],
      temperature: 0.82,
      max_tokens: 900,
      response_format: { type: 'json_object' },
    });

    const raw = completion.choices[0].message.content;
    const parsed = JSON.parse(raw);

    if (!parsed.v1 || !parsed.v2 || !parsed.v3) {
      throw new Error('Unexpected response shape from AI');
    }

    return res.status(200).json({ success: true, v1: parsed.v1, v2: parsed.v2, v3: parsed.v3 });
  } catch (err) {
    console.error('ReplyTonic API error:', err);
    return res.status(500).json({ error: 'AI rewrite failed. Please try again.' });
  }
}
