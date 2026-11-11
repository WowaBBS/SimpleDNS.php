<?
/* ---------- Ёкранирование строк (TXT, CAA) ---------- */
final class StrCodec
{
  public static function escape(string $s): string
  {
    return addcslashes($s, "\0..\37\"\\");
  }

  public static function unescape(string $s): string
  {
    return stripcslashes($s);
  }
}
