// Candidate extraction for manual curation: the Prophet's own words from reminder-type books.
// Usage: node tools/candidates.mjs <raw dir> <out.json>
import fs from 'node:fs';
import path from 'node:path';
const [dir, out] = process.argv.slice(2);
const NAMES = { bukhari: 'Sahih al-Bukhari', muslim: 'Sahih Muslim', abudawud: 'Sunan Abi Dawud', tirmidhi: 'Jami at-Tirmidhi', nasai: "Sunan an-Nasa'i", ibnmajah: 'Sunan Ibn Majah', malik: 'Muwatta Malik' };
// [collection, book, tag, tier]
const BOOKS = [
  ['bukhari', 2, 'faith', 1], ['bukhari', 3, 'knowledge', 1], ['bukhari', 19, 'night', 1], ['bukhari', 66, 'quran', 1], ['bukhari', 78, 'character', 1], ['bukhari', 81, 'dunya', 2],
  ['muslim', 1, 'faith', 1], ['muslim', 45, 'character', 1], ['muslim', 47, 'knowledge', 1], ['muslim', 48, 'dhikr', 1], ['muslim', 49, 'dunya', 2], ['muslim', 50, 'repent', 1],
  ['muslim', 51, 'hypocrite', 4], ['muslim', 52, 'death', 3], ['muslim', 53, 'paradise', 1], ['muslim', 55, 'dunya', 2],
  ['tirmidhi', 27, 'character', 1], ['tirmidhi', 36, 'dunya', 2], ['tirmidhi', 37, 'death', 3], ['tirmidhi', 38, 'paradise', 1], ['tirmidhi', 39, 'hell', 5], ['tirmidhi', 40, 'faith', 1], ['tirmidhi', 41, 'knowledge', 1], ['tirmidhi', 43, 'character', 1], ['tirmidhi', 45, 'quran', 1],
  ['ibnmajah', 37, 'dunya', 2], ['ibnmajah', 0, 'faith', 1], ['ibnmajah', 33, 'character', 1], ['abudawud', 26, 'knowledge', 1], ['nasai', 47, 'faith', 1], ['nasai', 20, 'night', 1],
  ['malik', 47, 'character', 1], ['malik', 54, 'dunya', 2], ['malik', 56, 'character', 1], ['malik', 57, 'hell', 5], ['malik', 7, 'night', 1],
];
const AVOID = /menstru|intercourse|genital|urine|urinat|privy|tayammum|ablution|wudu|sexual|wife|wives|husband|divorce|slave girl|zakat|camel|sheep|goat|inherit|usury|riba|fornicat|adulter|testicl|semen|circumcis|dowry|menses|saliva|naked|ihram|tawaf|sacrific|locust|dog|pig|wine|intoxic|jizya|battle|sword|spoils|captive|horse|dirham|dinar|hajj|umrah|martyr|killed|fight/i;
const clean = x => x.replace(/\s+/g, ' ').replace(/^[“"'`\s]+|[”"'`\s]+$/g, '').trim();
const cands = [];
const seen = new Set();
for (const [c, book, tag, tier] of BOOKS) {
  const f = path.join(dir, `eng-${c}.json`);
  const ar = new Map(JSON.parse(fs.readFileSync(path.join(dir, `ara-${c}.json`), 'utf8')).hadiths.map(h => [h.hadithnumber, h.text]));
  for (const h of JSON.parse(fs.readFileSync(f, 'utf8')).hadiths) {
    if (h.reference.book !== book) continue;
    const t = String(h.text || '').replace(/\s+/g, ' ');
    const m = t.match(/(?:\bsaid|\bsaying|\bsays?|\bsay)\s*[,:]?\s*[“"'`]+(.{40,900}?)(?:[”"']+\s*$|[”"']+\s+[A-Z]|$)/) || t.match(/\bas saying:?\s*(.{40,900})$/);
    if (!m) continue;
    let sp = clean(m[1]);
    const sents = sp.split(/(?<=[.!?])\s+(?=[A-Z“"'`])/);
    let piece = '';
    for (const s of sents) { if ((piece + ' ' + s).trim().length > 300) break; piece = (piece + ' ' + s).trim(); }
    if (piece.length < 45) continue;
    if (/\?/.test(piece) || /^o allah/i.test(piece) || AVOID.test(piece)) continue;
    const key = piece.toLowerCase().replace(/[^a-z]/g, '').slice(0, 70);
    if (seen.has(key)) continue; seen.add(key);
    let grade = (c === 'bukhari' || c === 'muslim') ? 'sahih' : 'ungraded', gby = (c === 'bukhari' || c === 'muslim') ? 'agreed upon' : '';
    if (!(c === 'bukhari' || c === 'muslim') && h.grades && h.grades.length) {
      const p = h.grades.find(g => /albani/i.test(g.name)) || h.grades[0];
      const g = String(p.grade).toLowerCase();
      grade = /maudu|mawdu|fabricat|munkar/.test(g) ? 'fabricated' : /da['’]?e?i?f|weak/.test(g) ? 'daif' : /hasan/.test(g) ? 'hasan' : /sahih/.test(g) ? 'sahih' : 'ungraded'; gby = p.name;
    }
    if (grade === 'fabricated') continue;
    cands.push({ id: `${c}:${h.hadithnumber}`, en: piece, ar: (ar.get(h.hadithnumber) || '').replace(/\s+/g, ' ').trim(), src: `${NAMES[c]} ${h.hadithnumber}`, grade, gby, tag, tier, full: t.length > piece.length ? t.slice(0, 900) : '' });
  }
}
fs.writeFileSync(out, JSON.stringify(cands));
console.log(cands.length);
