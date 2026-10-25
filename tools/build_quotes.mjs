// Build data-src/quotes.ndjson from the fawazahmed0/hadith-api editions (eng-* + ara-*).
// Usage: node tools/build_quotes.mjs <dir with eng-*.json and ara-*.json> [out.ndjson]
//
// Two pools:
//   core    hand-picked phrases (SEEDS) matched against the whole corpus, so the exact dataset wording and grade are kept
//   general standing statements from books about faith, heart-softening, zuhd, repentance, the Hereafter, knowledge, character
// Fabricated (mawdu') narrations are never included. Weak ones are kept and carry grade "daif".
import fs from 'node:fs';
import path from 'node:path';

const dir = process.argv[2];
const out = process.argv[3] || path.join(import.meta.dirname, '..', 'data-src', 'quotes.ndjson');
if (!dir) { console.error('usage: node tools/build_quotes.mjs <raw dir> [out]'); process.exit(1); }

const NAMES = {
  bukhari: 'Sahih al-Bukhari', muslim: 'Sahih Muslim', abudawud: 'Sunan Abi Dawud',
  tirmidhi: 'Jami at-Tirmidhi', nasai: "Sunan an-Nasa'i", ibnmajah: 'Sunan Ibn Majah',
  malik: 'Muwatta Malik',
};
const ALWAYS_SAHIH = new Set(['bukhari', 'muslim']);

// [collection, book number, tag, base tier, reminderBook]. A reminder book (Riqaq, Zuhd, Repentance, Hereafter...) is reminder material throughout.
// Shown as the opening sentences; the app shows the full narration on tap.
// Chosen from each collection's own book list.
const BOOKS = [
  ['bukhari', 2, 'faith', 1], ['bukhari', 3, 'knowledge', 1], ['bukhari', 19, 'night', 1], ['bukhari', 66, 'quran', 1],
  ['bukhari', 78, 'character', 1], ['bukhari', 81, 'dunya', 2, true], ['bukhari', 92, 'death', 3], ['bukhari', 96, 'faith', 1],
  ['muslim', 1, 'faith', 1], ['muslim', 47, 'knowledge', 1], ['muslim', 48, 'dhikr', 1], ['muslim', 49, 'dunya', 2, true],
  ['muslim', 50, 'repent', 1, true], ['muslim', 51, 'hypocrite', 4, true], ['muslim', 52, 'death', 3, true], ['muslim', 53, 'paradise', 1, true],
  ['muslim', 55, 'dunya', 2, true], ['muslim', 45, 'character', 1],
  ['tirmidhi', 27, 'character', 1], ['tirmidhi', 36, 'dunya', 2, true], ['tirmidhi', 37, 'death', 3, true], ['tirmidhi', 38, 'paradise', 1, true],
  ['tirmidhi', 39, 'hell', 5, true], ['tirmidhi', 40, 'faith', 1], ['tirmidhi', 41, 'knowledge', 1], ['tirmidhi', 43, 'character', 1],
  ['tirmidhi', 45, 'quran', 1],
  ['ibnmajah', 37, 'dunya', 2, true], ['ibnmajah', 36, 'death', 3], ['ibnmajah', 0, 'faith', 1], ['ibnmajah', 33, 'character', 1],
  ['abudawud', 26, 'knowledge', 1],
  ['nasai', 47, 'faith', 1], ['nasai', 20, 'night', 1],
  ['malik', 47, 'character', 1], ['malik', 54, 'dunya', 2, true], ['malik', 56, 'character', 1], ['malik', 57, 'hell', 5, true],
  ['malik', 58, 'charity', 1], ['malik', 59, 'knowledge', 1], ['malik', 15, 'quran', 1], ['malik', 7, 'night', 1],
];

// [regex on lowercased text, tags, tier]. Each seed is a well-known narration about prayer, time or the night.
const SEEDS = [
  [/heaviest prayers? (for|upon|on) the hypocrites/, ['salah', 'fajr', 'isha', 'jamaah', 'hypocrite'], 3],
  [/covenant (that is )?(between|which (is )?between) us and them is (the )?prayer/, ['salah', 'hell'], 5],
  [/between (a|the) (man|person|servant) and (polytheism|shirk|disbelief|kufr)[^.]*(abandon|leaving|neglect|giving up)[^.]*prayer/, ['salah', 'hell'], 5],
  [/almost (ordered|commanded)[^.]*(wood|firewood)[^.]*(burn|houses)|burn the houses of (those|the ones)/, ['salah', 'jamaah', 'hell'], 5],
  [/first (thing|matter|of his deeds)[^.]*(called to account|reckon\w*|account)[^.]*prayer/, ['salah', 'death'], 3],
  [/whoever (misses|forgoes|leaves|abandons|omits)[^.]*asr[^.]*(lost|family|property|deeds? (is|are) (in vain|nullified))/, ['salah', 'hell'], 4],
  [/(missed|miss\w*) the .?asr prayer[^.]*(family|property)/, ['salah', 'hell'], 4],
  [/whoever (prays?|prayed) the two cool/, ['salah', 'fajr', 'paradise'], 1],
  [/(whoever|he who) (prays?|prayed|offers?) (the )?(fajr|morning prayer|subh)[^.]*(protection|care) of allah/, ['salah', 'fajr'], 2],
  [/(prays?|prayed|offer\w*) (the )?isha['’`]? in congregation[^.]*(half|whole) (of )?the night/, ['salah', 'isha', 'jamaah', 'night'], 2],
  [/prayer in congregation is (twenty|better|superior)/, ['salah', 'jamaah'], 2],
  [/two (rak.?ahs?|rak.?ats?|rak.?at)[^.]*(before )?fajr[^.]*better than (the )?(world|dunya)/, ['salah', 'fajr', 'paradise'], 1],
  [/(best|dearest|most beloved) (deed|of deeds|action)[^.]*prayer[^.]*(proper|its|right|fixed) time/, ['salah', 'time'], 2],
  [/prayer at (its )?(proper|early|prescribed) time/, ['salah', 'time'], 2],
  [/satan ties three knots|three knots/, ['sleep', 'fajr', 'night'], 3],
  [/urinated in his ears?|devil urinated|satan urinated/, ['sleep', 'fajr', 'isha'], 4],
  [/our lord[^.]*descends[^.]*(last|remaining) (third|part)[^.]*night/, ['night', 'sleep', 'paradise'], 1],
  [/(the )?best (of )?prayers? (after|besides)[^.]*(obligatory|prescribed)[^.]*(night|midnight)/, ['night', 'sleep'], 1],
  [/two blessings[^.]*(health|free time|leisure)/, ['time', 'health'], 2],
  [/strong believer is (better|more beloved)/, ['health', 'faith'], 2],
  [/(servant|son of adam)[^.]*will not (move|stand|budge|pass)[^.]*(until|before)[^.]*asked[^.]*(life|youth|wealth|body)/, ['time', 'death'], 3],
  [/(whoever|he who) (treads|travels|follows) a path[^.]*knowledge/, ['knowledge', 'paradise'], 1],
  [/prayer of the hypocrite[^.]*(sun|horns)|sits? watching the sun[^.]*horns of (the )?(satan|devil)/, ['salah', 'hypocrite', 'time'], 4],
  [/(whoever|he who) (goes|go|walks?) to the mosque[^.]*(morning|evening)[^.]*(prepare|abode|hospitality)/, ['jamaah', 'paradise'], 1],
  [/(each|every) step[^.]*(raises|elevates)[^.]*(degree|rank)|every step[^.]*sin (is )?(erased|wiped)/, ['jamaah', 'paradise'], 1],
  [/(pray|prayer)[^.]*before (the )?sunrise[^.]*before (the )?sunset[^.]*(not enter|will not enter) (the )?(hell|fire)/, ['salah', 'fajr', 'hell'], 2],
  [/(whoever|he who) (prays?|prayed|offers?) (the )?fajr[^.]*(sits?|remain\w*)[^.]*(remembering|dhikr)[^.]*(sun rise|sunrise)[^.]*(two )?rak/, ['fajr', 'dhikr', 'paradise'], 1],
  [/(whoever|he who) (hears|heard) the (adhan|call)[^.]*(does not|doesn't|fails to|without) (answer|respond|come|go)/, ['salah', 'jamaah', 'hell'], 4],
  [/(there is|is there) no (prayer|salat) more (burdensome|heavy|difficult)[^.]*(hypocrites|fajr|isha)/, ['salah', 'fajr', 'isha', 'hypocrite'], 3],
  [/(whoever|he who) (loses|forfeits|forgoes)[^.]*prayer[^.]*(allah|deeds)/, ['salah', 'hell'], 4],
  [/(be|are) (in this world|in the world) like a (stranger|traveller|wayfarer)/, ['dunya', 'time'], 2],
  [/(he|whoever)[^.]*(wakes|wake) (up )?(his|her) (family|wife)[^.]*(night|pray)/, ['night', 'sleep'], 1],
  [/the (most )?beloved of (deeds|actions) to allah[^.]*(constant|consistent|regular|continuous)/, ['deeds', 'faith'], 1],
  [/(your|the) body has a right (over|on|upon) you/, ['health', 'night'], 1],
];

const AVOID = /menstru|intercourse|genital|urine|urinat|privy|privies|tayammum|ablution|wudu|sexual|wife|wives|husband|divorce|slave girl|zakat|camel|sheep|goat|inherit|usury|riba|fornicat|adulter|testicl|semen|pubic|circumcis|dowry|menses|bleeding|saliva|nipple|breast|thigh|naked|ihram|tawaf|sacrific|locust|dog|pig|wine|intoxic|jizya|dhimmi|battle|sword|spoils|captive|leprosy|vomit|bequest|horse|mule|donkey|dirham|dinar|ounce/i;
const DANGLING = /^(he|she|they|it|him|her|his|and|but|then|so|thereupon|when|while|after|that|this|these|those who were)\b/i;
const NARRATIVE = /\b(i saw|i heard|i came|i was|i went|we were|we went|we used|used to|was |were |had |once |came to|went to|asked|said to|told|entered|returned|narrated|reported)\b/i;
const NORMATIVE = /^(whoever|whosoever|he who|she who|the one who|one who|those who|none of you|no one|nobody|no (muslim|believer|servant|person|man)|if (anyone|someone|a man|a person|a servant|you|any one)|the (best|worst|most|first|key|difference|covenant|prayer|prayers|hypocrite|hypocrites|believer|believers|strong|people|servant|slave|likeness|example|parable|reward|dearest|beloved|sign|signs|one|person|man|muslim|world|dunya|hereafter|heart|tongue|deeds|deed)|allah|indeed|verily|surely|beware|woe|do not|don't|never|let |hasten|there (is|are)|a (person|man|servant|believer|muslim|slave)|two |whenever|when (a|the) (servant|man|person|believer)|my (ummah|nation)|every|each|between|seek|strive|fear|take|remember|be |hold|stay|keep|know|learn|recite|spread|give|speak|love|do |part of|faith|modesty|patience|truthful|honesty|kindness|gentleness|righteousness|wealth|knowledge)/i;

function gradeOf(c, grades) {
  if (ALWAYS_SAHIH.has(c)) return { grade: 'sahih', by: 'agreed upon' };
  if (!grades || !grades.length) return { grade: 'ungraded', by: '' };
  const pick = grades.find(g => /albani/i.test(g.name)) || grades[0];
  const g = String(pick.grade).toLowerCase();
  let grade = 'ungraded';
  if (/maudu|mawdu|fabricat|batil|munkar/.test(g)) grade = 'fabricated';
  else if (/da['’]?e?i?f|weak/.test(g)) grade = 'daif';
  else if (/hasan/.test(g)) grade = 'hasan';
  else if (/sahih|authentic/.test(g)) grade = 'sahih';
  return { grade, by: pick.name };
}

function load(prefix, c) {
  const f = path.join(dir, `${prefix}-${c}.json`);
  return fs.existsSync(f) ? JSON.parse(fs.readFileSync(f, 'utf8')).hadiths : [];
}

function stripNarrator(t) {
  const m = t.match(/^(?:It was )?[Nn]arrated (?:that )?([^:]{2,60}):\s*(.*)$/);
  return m ? { who: m[1].trim(), text: m[2].trim() } : { who: '', text: t };
}

const clean = x => x.replace(/\s+/g, ' ').replace(/^[“"'`\s]+|[”"'`\s]+$/g, '').trim();

// A window of whole sentences (<= max chars) starting at the first sentence that satisfies pred.
function sentenceWindow(text, pred, max) {
  const sents = text.split(/(?<=[.!?])\s+(?=[A-Z“"'`])/).map(x => x.trim()).filter(Boolean);
  for (let i = 0; i < sents.length; i++) {
    if (!pred(sents[i])) continue;
    let piece = sents[i];
    if (piece.length > max) return '';
    for (let j = i + 1; j < sents.length && piece.length + sents[j].length + 1 <= max && !DANGLING.test(sents[j]); j++) piece += ' ' + sents[j];
    return clean(piece);
  }
  return '';
}

const items = [];
const seen = new Set();
const stats = { core: {}, general: 0, athar: 0 };

function push(it) {
  const key = it.en.toLowerCase().replace(/[^a-z]/g, '').slice(0, 80);
  if (seen.has(key) || it.en.length < 30) return false;
  seen.add(key);
  items.push(it);
  return true;
}

for (const c of Object.keys(NAMES)) {
  const en = load('eng', c);
  const ar = new Map(load('ara', c).map(h => [h.hadithnumber, h.text]));
  const bookTag = new Map(BOOKS.filter(b => b[0] === c).map(b => [b[1], b]));
  for (const h of en) {
    const raw = String(h.text || '').replace(/\s+/g, ' ').trim();
    const { who, text: body } = stripNarrator(raw);
    const { grade, by } = gradeOf(c, h.grades);
    if (grade === 'fabricated') continue;
    const num = h.hadithnumber;
    const base = {
      id: `${c}:${num}`, ar: (ar.get(num) || '').replace(/\s+/g, ' ').trim(),
      src: `${NAMES[c]} ${num}`, who, grade, gby: by, kind: 'hadith',
    };
    const low = body.toLowerCase();

    let isCore = false;
    SEEDS.forEach(([re, tags, tier], si) => {
      if (isCore || !re.test(low)) return;
      const ex = sentenceWindow(body, s => re.test(s.toLowerCase()), 420) || sentenceWindow(body, () => true, 420);
      if (!ex || AVOID.test(ex)) return;
      if (push({ ...base, en: ex, full: body.length > ex.length ? body.slice(0, 900) : '', tags, tier, core: true })) {
        stats.core[si] = (stats.core[si] || 0) + 1;
        isCore = true;
      }
    });
    if (isCore) continue;

    const book = bookTag.get(h.reference.book);
    if (!book) continue;
    let ex = body.length <= 260 ? clean(body) : '';
    if (book[4] && !ex) {
      ex = sentenceWindow(body, () => true, 300);
      if (!ex) ex = clean(body.slice(0, 297)).replace(/\s+\S*$/, '') + '\u2026';
    }
    if (!ex) {
      const quoted = [...body.matchAll(/[“"]([^”"]{40,600})[”"]/g)].map(m => m[1]);
      for (const q of quoted.length ? quoted : [body]) {
        ex = sentenceWindow(q, s => s.length >= 40 && s.length <= 260 && NORMATIVE.test(s) && !DANGLING.test(s), 260);
        if (ex) break;
      }
    }
    if (book[4]) { if (!ex || ex.length < 50 || AVOID.test(ex) || /\?/.test(ex) || /^o allah\b/i.test(ex) || DANGLING.test(ex)) continue; }
    else if (!ex || ex.length < 40 || AVOID.test(ex) || DANGLING.test(ex) || NARRATIVE.test(ex.slice(0, 70)) || !NORMATIVE.test(ex)) continue;
    if (book[4]) { /* reminder book: already checked */ } else if (/^o allah\b/i.test(ex) || ex.length < 60 || /\?/.test(ex) || !/[.!)\u201d"']$/.test(ex)) continue;
    if (!/\b(allah|faith|believer|paradise|hell|fire|death|world|hereafter|judgement|resurrection|heart|deeds?|sins?|repent\w*|knowledge|truth\w*|prayer|quran|reward|patien\w+|modest\w*|kind\w*|good|righteous\w*)\b/i.test(ex)) continue;
    if (push({ ...base, en: ex, full: body.length > ex.length ? body.slice(0, 900) : '', tags: [book[2]], tier: book[3] })) stats.general++;
  }
}

// Companion and Successor sayings preserved in the Muwatta (marked "athar", never presented as hadith).
for (const h of load('eng', 'malik')) {
  const t = String(h.text || '').replace(/\s+/g, ' ');
  const m = t.match(/that ((?:Umar|Ali|Abdullah ibn (?:Umar|Mas'ud)|Abu Bakr|Aisha|Ibn Abbas|Said ibn al-Musayyab|al-Hasan|Abu ad-Darda|Salman|Umar ibn Abd al-Aziz)[^"]{0,60}?) (?:said|used to say|wrote),?\s*[“"'`]+([^”"]{40,260})[”"']+/);
  if (!m) continue;
  const ex = clean(m[2]);
  if (AVOID.test(ex) || NARRATIVE.test(ex.slice(0, 60))) continue;
  if (push({ id: `malik:${h.hadithnumber}`, ar: '', en: ex, full: '', src: `Muwatta Malik ${h.hadithnumber}`, who: m[1].trim(), grade: 'ungraded', gby: '', kind: 'athar', tags: ['character'], tier: 2 })) stats.athar++;
}

fs.mkdirSync(path.dirname(out), { recursive: true });
fs.writeFileSync(out, items.map(i => JSON.stringify(i)).join('\n') + '\n');

const by = f => items.reduce((a, i) => { for (const k of [].concat(f(i))) a[k] = (a[k] || 0) + 1; return a; }, {});
console.log(JSON.stringify({
  kept: items.length, coreSeedsHit: Object.keys(stats.core).length + '/' + SEEDS.length, coreItems: items.filter(i => i.core).length,
  general: stats.general, athar: stats.athar, grade: by(i => i.grade), tier: by(i => i.tier), tags: by(i => i.tags),
}));
const missing = SEEDS.map((s, i) => stats.core[i] ? null : i).filter(x => x !== null);
console.log('seeds with no match: ' + missing.join(','));
