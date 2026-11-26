<?
/* ============================================================
 * Ёкранирование строк (TXT, CAA) в презентационном виде.
 * RFC 8427: печатный ASCII пишетс€ как есть, кавычка и обратный
 * слэш - с обратным слэшем, всЄ остальное - \DDD (дес€тичное).
 * Ѕлагодар€ этому текст RDATA всегда ASCII и безопасен дл€ JSON.
 * ============================================================ */

final class StrCodec
{
  public static function escape(string $s): string
  {
    $out = '';
    for ($i = 0, $n = strlen($s); $i < $n; $i++)
    {
      $c = $s[$i];
      $o = ord($c);

      if ($c === '"' || $c === '\\')
        $out .= '\\' . $c;
      elseif ($o < 0x20 || $o > 0x7E)
        $out .= '\\' . sprintf('%03d', $o);
      else
        $out .= $c;
    }
    return $out;
  }

  public static function unescape(string $s): string
  {
    $out = '';
    for ($i = 0, $n = strlen($s); $i < $n; $i++)
    {
      if ($s[$i] !== '\\')
      {
        $out .= $s[$i];
        continue;
      }

      $i++;
      if ($i >= $n)
      {
        $out .= '\\';
        break;
      }

      if ($i + 2 < $n && ctype_digit(substr($s, $i, 3)))
      {
        $out .= chr((int)substr($s, $i, 3));
        $i += 2;
      }
      else
        $out .= $s[$i];
    }
    return $out;
  }

  static function test(TestRunner $t)
  {
    $t->eq(self::escape('abc')          , 'abc'           , 'StrCodec: ASCII без изменений');
    $t->eq(self::escape('say "hi"')     , 'say \\"hi\\"'  , 'StrCodec: кавычки');
    $t->eq(self::escape("back\\slash")  , 'back\\\\slash' , 'StrCodec: обратный слэш');
    $t->eq(self::escape("a\tb")         , 'a\\009b', 'StrCodec: управл€ющий байт -> \\DDD');
    $t->eq(self::escape("\xCF\xF0\xE8") , '\\207\\240\\232', 'StrCodec: байты 1251 -> \\DDD');

    $t->eq(self::unescape('\\207\\240\\232'), "\xCF\xF0\xE8", 'StrCodec: \\DDD обратно в байты');
    $t->eq(self::unescape('abc'), 'abc', 'StrCodec: unescape ASCII');
    $t->eq(self::unescape(self::escape("a\"b\\c\x01")), "a\"b\\c\x01", 'StrCodec: roundtrip любых байт');
    $t->ok(ctype_print(self::escape("\xCF\xF0\xE8")), 'StrCodec: escape даЄт только печатный ASCII');
  }
}