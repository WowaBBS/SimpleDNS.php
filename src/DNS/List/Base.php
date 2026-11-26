<?
/* ============================================================
 * DnsList - список DNS-объектов с поведением массива.
 * ArrayAccess/Countable/IteratorAggregate сохраняют привычный
 * синтаксис: $list[] = $item, count($list), foreach, $list[0].
 * Здесь же живёт всё, что относится к секции целиком: запись
 * в бинарный поток, JSON-секция, чтение по счётчику заголовка.
 * ============================================================ */

abstract class DnsList implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
  /** @var array<int,object> */
  private array $items = [];

  /** @return class-string класс допустимого элемента */
  abstract protected static function itemClass(): string;

  /* ---------- Наполнение ---------- */

  public function add(mixed $item): static
  {
    $this->check($item);
    $this->items[] = $item;
    return $this;
  }

  public function merge(iterable $items): static
  {
    foreach ($items as $item)
      $this->add($item);

    return $this;
  }

  /* ---------- Поведение массива ---------- */

  public function offsetExists(mixed $offset): bool { return isset($this->items[$offset]); }

  public function offsetGet(mixed $offset): object
  {
    if (!isset($this->items[$offset]))
      throw new OutOfBoundsException("Нет элемента списка с индексом $offset");

    return $this->items[$offset];
  }

  /** Допустимы только плотные списки: добавление в конец и замена существующего индекса */
  public function offsetSet(mixed $offset, mixed $value): void
  {
    $this->check($value);

    if ($offset === null || $offset === count($this->items))
    {
      $this->items[] = $value;
      return;
    }

    if (!is_int($offset))
      throw new InvalidArgumentException('Индекс списка должен быть целым');

    if ($offset < 0 || $offset > count($this->items))
      throw new OutOfBoundsException("Индекс $offset вне списка (элементов: " . count($this->items) . ')');

    $this->items[$offset] = $value;
  }

  /** Удаление сохраняет плотность списка */
  public function offsetUnset(mixed $offset): void
  {
    if (!isset($this->items[$offset]))
      return;

    unset($this->items[$offset]);
    $this->items = array_values($this->items);
  }

  public function count(): int { return count($this->items); }

  public function getIterator(): ArrayIterator { return new ArrayIterator($this->items); }

  /** json_encode($list) даёт массив объектов, а не {} */
  public function jsonSerialize(): array
  {
    return array_map(fn(object $item) => $item->toJson(), $this->items);
  }

  /* ---------- Доступ ---------- */

  /** @return array<int,object> настоящий массив для array_* функций */
  public function toArray(): array { return $this->items; }

  public function isEmpty(): bool { return $this->items === []; }

  public function first(): ?object { return $this->items[0] ?? null; }

  public function last(): ?object
  {
    return $this->items === [] ? null : $this->items[count($this->items) - 1];
  }

  public function filter(callable $fn): static
  {
    $result = new static();
    foreach ($this->items as $item)
      if ($fn($item))
        $result->add($item);

    return $result;
  }

  /* ---------- Бинарная секция ---------- */

  public function writeTo(DnsWriter $w): void
  {
    foreach ($this->items as $item)
      $item->writeTo($w);
  }

  public static function fromReader(DnsReader $r, int $count): static
  {
    $class = static::itemClass();
    $list  = new static();

    for ($i = 0; $i < $count; $i++)
    {
      [$item] = $class::fromReader($r);
      $list->add($item);
    }

    return $list;
  }

  /* ---------- JSON-секция ---------- */

  public function writeJsonTo(JsonWriter $w, string $key): void
  {
    $w->openList($key);
    foreach ($this->items as $item)
      $item->writeToJson($w);
    $w->close();
  }

  public static function fromJsonReader(JsonReader $j, string $key): static
  {
    $class = static::itemClass();
    $list  = new static();

    foreach ($j->list($key) as $item)
      $list->add($class::fromJsonReader($item));

    return $list;
  }

  public function __toString(): string
  {
    return sprintf('%s(%d)', static::class, count($this->items));
  }

  private function check(mixed $item): void
  {
    $class = static::itemClass();

    if (!is_object($item) || !$item instanceof $class)
      throw new InvalidArgumentException(sprintf('%s принимает только %s, получено %s',
        static::class, $class, get_debug_type($item)));
  }

  /* ------------------------------------------------------------
   * Тесты
   * ------------------------------------------------------------ */

  static function test(TestRunner $t)
  {
    $q1 = new DnsQuestion('a.example.com', 1);
    $q2 = new DnsQuestion('b.example.com', 28);

    $list = new DnsQuestionList();
    $list[] = $q1;              // ArrayAccess: добавление в конец
    $list->add($q2);

    $t->eq(count($list), 2, 'DnsList: count после добавления');
    $t->eq($list[0], $q1, 'DnsList: доступ по индексу');
    $t->eq($list->first(), $q1, 'DnsList: first');
    $t->eq($list->last(), $q2, 'DnsList: last');
    $t->eq($list->isEmpty(), false, 'DnsList: isEmpty');
    $t->eq((new DnsQuestionList())->isEmpty(), true, 'DnsList: пустой список');

    $names = [];
    foreach ($list as $item) $names[] = $item->name;
    $t->eq($names, ['a.example.com', 'b.example.com'], 'DnsList: foreach по элементам');

    // Тип элемента проверяется на любом входе
    $t->throws(fn() => $list->add(new DnsRecord('x.example.com', new ARecord('1.2.3.4'))), 'DnsList: элемент чужого типа -> исключение');
    $t->throws(fn() => $list->add('строка'), 'DnsList: не объект -> исключение');
    $t->throws(fn() => $list[5] = $q1, 'DnsList: индекс за границей -> исключение');
    $t->throws(fn() => $list['a'] = $q1, 'DnsList: нецелый индекс -> исключение');
    $t->throws(fn() => $list[7], 'DnsList: чтение за границей -> исключение');

    // Замена и плотность после удаления
    $list[0] = $q2;
    $t->eq($list[0], $q2, 'DnsList: замена по индексу');
    $list[0] = $q1;

    unset($list[0]);
    $t->eq(count($list), 1, 'DnsList: unset уменьшает count');
    $t->eq($list[0], $q2, 'DnsList: unset переиндексирует');

    // filter сохраняет тип списка
    $filtered = $list->filter(fn(DnsQuestion $q) => $q->type === 28);
    $t->eq(count($filtered), 1, 'DnsList: filter');
    $t->eq($filtered instanceof DnsQuestionList, true, 'DnsList: filter возвращает тот же тип');

    // Бинарная секция по счётчику
    $w = new DnsWriter();
    $list->writeTo($w);
    $back = DnsQuestionList::fromReader(DnsReader::of($w->data()), 1);
    $t->eq($back[0]->name, 'b.example.com', 'DnsList: бинарная секция пишется и читается');

    // JSON-секция
    $sink = new JsonStreamSink();
    $writer = new JsonWriter($sink);
    $writer->openObject();
    $list->writeJsonTo($writer, 'Question');
    $writer->close();
    $t->eq($sink->data(), '{"Question":[{"Name":"b.example.com.","Type":"AAAA"}]}', 'DnsList: JSON-секция');

    $json = DnsQuestionList::fromJsonReader(new JsonReader(json_decode($sink->data(), true)), 'Question');
    $t->eq($json[0]->name, 'b.example.com', 'DnsList: JSON-секция читается обратно');

    // json_encode списка даёт массив, а не объект
    $t->eq(json_encode($list), '[{"Name":"b.example.com.","Type":"AAAA"}]', 'DnsList: json_encode -> массив');
  }
}