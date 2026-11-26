<?
/* ============================================================
 * –еестр: код типа -> класс RDATA
 * ============================================================ */

final class RdataRegistry
{
  private const MAP = [
      1 => ARecord     ::class,
      2 => NsRecord    ::class,
      5 => CnameRecord ::class,
      6 => SoaRecord   ::class,
     12 => PtrRecord   ::class,
     15 => MxRecord    ::class,
     16 => TxtRecord   ::class,
     28 => AaaaRecord  ::class,
     33 => SrvRecord   ::class,
     41 => OptRecord   ::class,
    257 => CaaRecord   ::class,
  ];

  public static function classFor(int $type): string
  {
    return self::MAP[$type] ?? UnknownRdata::class;
  }

  /** ќбратный поиск: класс RDATA -> код типа; null, если тип задаЄт экземпл€р */
  public static function typeFor(string $class): ?int
  {
    $type = array_search($class, self::MAP, true);
    return $type === false ? null : $type;
  }

  static function test(TestRunner $t)
  {
    $t->eq(RdataRegistry::classFor(  1), ARecord      ::class, 'RdataRegistry: A -> ARecord');
    $t->eq(RdataRegistry::classFor( 28), AaaaRecord   ::class, 'RdataRegistry: AAAA -> AaaaRecord');
    $t->eq(RdataRegistry::classFor(257), CaaRecord    ::class, 'RdataRegistry: CAA -> CaaRecord');
    $t->eq(RdataRegistry::classFor(999), UnknownRdata ::class, 'RdataRegistry: неизвестный -> UnknownRdata');
    $t->eq(RdataRegistry::typeFor(ARecord::class      ),    1, 'RdataRegistry: ARecord -> 1');
    $t->eq(RdataRegistry::typeFor(CaaRecord::class    ),  257, 'RdataRegistry: CaaRecord -> 257');
    $t->eq(RdataRegistry::typeFor(UnknownRdata::class ), null, 'RdataRegistry: UnknownRdata -> null');
  }
}
