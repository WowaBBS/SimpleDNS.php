<?
/* --- AAAA --- */
final class AaaaRecord extends DnsRdata
{
  public function __construct(public string $ip) {}

  public function serialize(): string
  {
    return inet_pton($this->ip) ?: throw new InvalidArgumentException("Некорректный IPv6: {$this->ip}");
  }

  public static function fromReader(DnsReader $r): static
  {
    if ($r->remaining() !== 16)
      throw new RuntimeException('Некорректная длина AAAA-записи: '.$r->remaining());
    
    return new static(inet_ntop($r->readBytes(16)));
  }

  public function __toString(): string { return $this->ip; }

  static function test(TestRunner $t)
  {
   $t->eq((AaaaRecord::fromReader(DnsReader::of(inet_pton('2001:db8::1'))))->ip, '2001:db8::1', 'AaaaRecord: roundtrip 2001:db8::1');
   $t->throws(fn() => AaaaRecord::fromReader(DnsReader::of(inet_pton('2001:db8::1'), 0, -1)), 'AaaaRecord: rdLen != 16 -> исключение');
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
