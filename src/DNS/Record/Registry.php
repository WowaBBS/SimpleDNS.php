<?
/* ============================================================
 * Реестр: код типа -> класс RDATA
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

  static function test(TestRunner $t)
  {
    $t->eq(RdataRegistry::classFor(  1), ARecord      ::class, 'RdataRegistry: A -> ARecord');
    $t->eq(RdataRegistry::classFor( 28), AaaaRecord   ::class, 'RdataRegistry: AAAA -> AaaaRecord');
    $t->eq(RdataRegistry::classFor(257), CaaRecord    ::class, 'RdataRegistry: CAA -> CaaRecord');
    $t->eq(RdataRegistry::classFor(999), UnknownRdata ::class, 'RdataRegistry: неизвестный -> UnknownRdata');
  }
}
