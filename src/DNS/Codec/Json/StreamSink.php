<?
/* ============================================================
 * JsonStreamSink - пишет JSON сразу в поток (файл, php://output),
 * накапливая текст в буфере, чтобы не дёргать запись на каждый
 * токен. Без потока работает как строковый приёмник (data()).
 * ============================================================ */

final class JsonStreamSink implements JsonSink
{
  private string $buf = '';

  /** @param resource|null $stream */
  public function __construct(
    private $stream = null,
    private int $threshold = 8192,
  ) {}

  public function open(string $kind, ?string $key): void
  {
    $this->buf .= ($key === null ? '' : self::keyText($key)) . ($kind === 'object' ? '{' : '[');
    $this->maybeFlush();
  }

  public function close(string $kind): void
  {
    $this->buf .= $kind === 'object' ? '}' : ']';
    $this->maybeFlush();
  }

  public function separator(): void { $this->buf .= ','; }

  public function scalar(string|int|bool|null $value, ?string $key): void
  {
    $this->buf .= ($key === null ? '' : self::keyText($key)) . self::encode($value);
    $this->maybeFlush();
  }

  public function flush(): void
  {
    if ($this->stream === null || $this->buf === '')
      return;

    fwrite($this->stream, $this->buf);
    $this->buf = '';
  }

  /** Накопленный текст (когда поток не задан) */
  public function data(): string { return $this->buf; }

  private function maybeFlush(): void
  {
    if ($this->stream !== null && strlen($this->buf) >= $this->threshold)
      $this->flush();
  }

  private static function keyText(string $key): string { return self::encode($key) . ':'; }

  public static function encode(string|int|bool|null $value): string
  {
    if ($value === null)  return 'null';
    if ($value === true)  return 'true';
    if ($value === false) return 'false';
    if (is_int($value))   return (string)$value;

    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false)
      throw new RuntimeException('JSON: строка не кодируется: ' . json_last_error_msg());

    return $json;
  }
}
