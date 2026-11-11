<?
/* --- AAAA --- */
final class AaaaRecord extends DnsRdata
{
  public function __construct(public string $ip) {}

  public function serialize(): string
  {
    return inet_pton($this->ip) ?: throw new InvalidArgumentException("Некорректный IPv6: {$this->ip}");
  }

  public static function deserialize(string $msg, int $off, int $rdLen): static
  {
    if ($rdLen !== 16)
      throw new RuntimeException("Некорректная длина AAAA-записи: $rdLen");
    
    return new static(inet_ntop(substr($msg, $off, 16)));
  }

  public function __toString(): string { return $this->ip; }

  static function test(TestRunner $t)
  {
   $t->eq((AaaaRecord::deserialize(inet_pton('2001:db8::1'), 0, 16))->ip, '2001:db8::1', 'AaaaRecord: roundtrip 2001:db8::1');
   $t->throws(fn() => AaaaRecord::deserialize(inet_pton('2001:db8::1'), 0, 15), 'AaaaRecord: rdLen != 16 -> исключение');
  }

//****************************************************************
// Json

  public function toJson(): string { return $this->ip; }

  public static function fromJson(string $data): static
  {
    $bin = inet_pton(trim($data)) ?: throw new RuntimeException("Некорректный IPv6: $data");
    return new static(inet_ntop($bin));
  }
//****************************************************************
}
