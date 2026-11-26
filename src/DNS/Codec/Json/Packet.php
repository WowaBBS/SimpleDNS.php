<?
/* ============================================================
 * JsonPacket - общий контракт JSON-представления пакета.
 * Зеркало трейта Packet для байтов: класс описывает только
 * последовательность полей (writeToJson) и разбор (fromJsonReader),
 * всё остальное - здесь.
 * ============================================================ */

trait JsonPacket
{
  abstract public function writeToJson(JsonWriter $w): void;

  abstract public static function fromJsonReader(JsonReader $j): static;

  /** fromJson(string|array) - тонкая обёртка, как deserialize() у Packet */
  public static final function fromJson(string|array $json): static
  {
    if (is_string($json))
      $json = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($json))
      throw new RuntimeException('JSON: ожидался объект');

    return static::fromJsonReader(new JsonReader($json));
  }

  /** Вложенные массивы (для сравнений и для тех, кому нужен массив) */
  public function toJson(): array
  {
    $sink = new JsonArraySink();
    $this->writeToJson(new JsonWriter($sink));
    return $sink->data();
  }

  /** Готовая строка JSON */
  public function toJsonString(): string
  {
    $sink = new JsonStreamSink();
    $this->writeToJson(new JsonWriter($sink));
    return $sink->data();
  }

  /** Прямая запись в поток без сборки дерева в памяти */
  public function toJsonStream($stream): void
  {
    $sink = new JsonStreamSink($stream);
    $this->writeToJson(new JsonWriter($sink));
    $sink->flush();
  }
}
