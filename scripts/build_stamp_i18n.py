#!/usr/bin/env python3
"""Build stamps_i18n.json — UI labels for stamp voice lines (ja / en / es / ko / zh).

Preserves existing ko/zh when re-running so Korean/Chinese are not dropped.
"""

from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OFFICIAL_EN_PATH = Path(__file__).resolve().parent / 'stamps_official_en.json'

# Spanish UI strings (kept from prior build; EN now follows LLSIF Global stamp art)
PHRASES_ES: dict[str, str] = {
    'あなたに届け！': '¡Te lo entrego!',
    'ありがとうございます♪': '¡Muchas gracias♪',
    'ありがとう！': '¡Gracias!',
    'いい感じ♪': '¡Se ve bien♪',
    'いけいけーっ！': '¡Vamos!',
    'おいで、リトルデーモン♪': 'Ven, pequeño demonio♪',
    'お覚悟！': '¡Prepárate!',
    'がんばルビィ！': '¡Haré mi Rubesty!',
    'き、緊張してきました…': 'Me estoy poniendo nerviosa…',
    'きゅーんとしちゃう♡': 'Me derritió el corazón♡',
    'さあ、はじめよう！': '¡Vamos, empecemos!',
    'すごく楽しいね♪': '¡Esto es muy divertido♪',
    'すっごく嬉しい！': '¡Estoy tan feliz!',
    'ちゅんちゅん♪': '¡Pío pío♪',
    'とっても楽しいです！': '¡Es muy divertido!',
    'とても楽しいです': 'Es muy divertido',
    'どっこいしょー！': '¡Hora del esfuerzo!',
    'にっこにっこにー♪': 'Sonrisa-sonrisa-sonrisa♪',
    'にゃにゃにゃー': '¡Miau miau miau!',
    'ぷにっ': '¡Boing!',
    'みんな、お疲れさま！': '¡Buen trabajo a todos!',
    'みんなの力をあわせよう！': '¡Unamos nuestras fuerzas!',
    'やったにゃー！': '¡Lo logramos, miau!',
    'やりましたわね！': '¡Lo logramos!',
    'やるじゃない': '¡No está mal!',
    'よしよし♡': 'Bien hecho♡',
    'アイムハッピー♡': 'Estoy feliz♡',
    'イエイっ！': '¡Yay!',
    'ウフフ♪': 'Ufufu♪',
    'オラにも出来る！': '¡Yo también puedo!',
    'ゴ〜ン': 'Se fue…',
    'サンキュ！': '¡Gracias!',
    'シャイニー☆': 'Brillante☆',
    'スピリチュアルやね': '¡Qué espiritual!',
    'ダレカタスケテー': '¡Alguien ayúdame!',
    'テンションあがるにゃー！': '¡Estoy con energía, miau!',
    'ドキドキ♡': 'Corazón acelerado♡',
    'ノープロブレム☆': 'Sin problema☆',
    'ハグしたくなっちゃう♪': '¡Quiero abrazarte♪',
    'ハグしよ♪': '¡Abrazémonos♪',
    'ハラショー！': '¡Harasho!',
    'ハーイ♪': '¡Hola♪',
    'ピギィッ': '¡Chiii!',
    'ファイトだよ！': '¡Tú puedes!',
    'ヨーソロー！': '¡Yousoro!',
    'ラブにこ♪': 'Amor, Nico♪',
    'ラブを届けますっ☆': '¡Entregando amor☆',
    'ラブアローシュート！': '¡Love Arrow Shoot!',
    'ルビィは幸せです♡': 'Ruby está feliz♡',
    '一緒に堕ちましょう♪': '¡Caigamos juntos♪',
    '一緒に楽しみましょう': 'Disfrutemos juntos',
    '全速前進！': '¡A toda velocidad!',
    '助太刀します！': '¡Te cubro!',
    '堕天降臨！': '¡Desciende el ángel caído!',
    '大丈夫だよ！': '¡Va a estar bien!',
    '嬉しい音が聞こえてくるの': 'Escucho sonidos felices',
    '希パワー注入♪': '¡Inyección de poder Nozomi♪',
    '当然ですわね': '¡Por supuesto!',
    '想いよ、響け♪': '¡Que resuenen mis sentimientos♪',
    '意味わかんない！': '¡No lo entiendo!',
    '敬礼っ！': '¡Saludo!',
    '最高です！': '¡Lo mejor!',
    '最高に最高の気分だよ♪': '¡Me siento increíble♪',
    '最高に楽しい！': '¡Muy divertido!',
    '最高の気分かも': 'Quizá sea el mejor sentimiento',
    '最高の気分ずら♪': '¡El mejor humor, zura♪',
    '未来ずら〜っ✨': '¡Es el futuro, zura✨',
    '栄光あれ！': '¡Gloria!',
    '楽しいわ♪': '¡Qué divertido♪',
    '楽しんでる？': '¿Te diviertes?',
    '歌の力ってすごいんだね！': '¡El poder de la canción es increíble!',
    '熱くなれそうだよ♪': '¡Me estoy animando♪',
    '癒してあげたい': 'Quiero sanarte',
    '盛り上がって来たわね': '¡Se está animando todo!',
    '私…頑張ります！': '¡Haré… mi mejor esfuerzo!',
    '精一杯の輝きを！': '¡Brilla con todo lo que tienes!',
    '舞い踊れ♪': '¡Baila♪',
    '頼もしいですね♪': '¡Qué confiable♪',
}


def main() -> None:
    official_en = json.loads(OFFICIAL_EN_PATH.read_text(encoding='utf-8'))
    manifest = json.loads((ROOT / 'stamps_manifest.json').read_text(encoding='utf-8'))
    prev: dict[str, dict[str, str]] = {}
    prev_path = ROOT / 'stamps_i18n.json'
    if prev_path.is_file():
        prev = (json.loads(prev_path.read_text(encoding='utf-8')).get('labels') or {})
    out: dict[str, dict[str, str]] = {}
    missing_en: set[str] = set()
    missing_es: set[str] = set()
    for loc in ('ja', 'en'):
        for row in manifest.get('locales', {}).get(loc, []):
            sid = row.get('id')
            ja = (row.get('label') or '').strip()
            if not sid:
                continue
            old = prev.get(sid) or {}
            if not ja:
                out[sid] = {
                    'ja': '', 'en': '', 'es': '',
                    'ko': old.get('ko', ''), 'zh': old.get('zh', ''),
                }
                continue
            en = official_en.get(ja)
            if not en:
                missing_en.add(ja)
                en = ja
            es = PHRASES_ES.get(ja)
            if not es:
                missing_es.add(ja)
                es = ja
            out[sid] = {
                'ja': ja,
                'en': en,
                'es': es,
                'ko': old.get('ko', ''),
                'zh': old.get('zh', ''),
            }
    dest = ROOT / 'stamps_i18n.json'
    dest.write_text(json.dumps({'version': 2, 'labels': out}, ensure_ascii=False, indent=2), encoding='utf-8')
    print(f'Wrote {dest} ({len(out)} stamps); preserved ko/zh from prior file')
    if missing_en:
        print('Missing official EN:', ', '.join(sorted(missing_en)))
    if missing_es:
        print('Missing ES:', ', '.join(sorted(missing_es)))


if __name__ == '__main__':
    main()
