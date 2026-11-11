<?
/* ============================================================
 * Секция QUESTION
 * ============================================================ */

final class DnsQuestion
{
  public function __construct(
    public string $name,
    public int    $type,
    public int    $class = 1, // IN
  ) {}

  public function serialize(): string
  {
    return NameCodec::encode($this->name) . pack('nn', $this->type, $this->class);
  }

  /** @return array{0:self, 1:int} */
  public static function deserialize(string $msg, int $off): array
  {
    [$name, $off] = NameCodec::decode($msg, $off);
    [$type, $class] = array_values(unpack('n2', $msg, $off));
    return [new self($name, $type, $class), $off + 4];
  }
}
