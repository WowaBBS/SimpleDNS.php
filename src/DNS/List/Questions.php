<?
/* ============================================================
 * DnsQuestionList - секци€ QUESTION.
 * ============================================================ */

final class DnsQuestionList extends DnsList
{
  protected static function itemClass(): string { return DnsQuestion::class; }

  public function byType(int $type): static
  {
    return $this->filter(fn(DnsQuestion $q) => $q->type === $type);
  }

  /** ѕоиск по имени (регистр и точка на конце не важны) и, если задан, по типу */
  public function find(string $name, ?int $type = null): ?DnsQuestion
  {
    $name = NameCodec::fromPresentation($name);

    foreach ($this->toArray() as $question)
      if (strcasecmp($question->name, $name) === 0 && ($type === null || $question->type === $type))
        return $question;

    return null;
  }

  /** @return string[] */
  public function names(): array
  {
    return array_map(fn(DnsQuestion $q) => $q->name, $this->toArray());
  }

  static function test(TestRunner $t)
  {
    $list = new DnsQuestionList();
    $list[] = new DnsQuestion('a.example.com',   1);
    $list[] = new DnsQuestion('b.example.com',  28);
    $list[] = new DnsQuestion('a.example.com',  28);

    $t->eq(count($list->byType(28)), 2, 'DnsQuestionList: byType');
    $t->eq(count($list->byType(6)),  0, 'DnsQuestionList: byType без совпадений');
    $t->eq($list->find('A.Example.com.')->type, 1, 'DnsQuestionList: find без учЄта регистра и точки');
    $t->eq($list->find('a.example.com', 28)->type, 28, 'DnsQuestionList: find с типом');
    $t->eq($list->find('zzz.example.com'), null, 'DnsQuestionList: find отсутствующего');
    $t->eq($list->names(), ['a.example.com', 'b.example.com', 'a.example.com'], 'DnsQuestionList: names');
    $t->eq($list->byType(28)->names(), ['b.example.com', 'a.example.com'], 'DnsQuestionList: byType -> names (цепочка)');
  }
}