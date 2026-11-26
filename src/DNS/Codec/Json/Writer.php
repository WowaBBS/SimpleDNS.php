<?
/* ============================================================
 * JsonWriter - единственное место, где решается структура JSON:
 * порядок полей, запятые, отложенные ключи и правила пропуска
 * пустых контейнеров. Куда писать - решает JsonSink.
 *
 * Зеркало DnsWriter из бинарного слоя: классы знают только
 * последовательность put/open/close, а не детали формата.
 * ============================================================ */

final class JsonWriter
{
  /** @var array<int,array{kind:string,key:?string,opened:bool,count:int}> */
  private array $stack = [];

  public function __construct(private JsonSink $sink) {}

  public function openObject(?string $key = null): self
  {
    $this->stack[] = ['kind'=>'object', 'key'=>$key, 'opened'=>false, 'count'=>0];
    return $this;
  }

  public function openList(?string $key = null): self
  {
    $this->stack[] = ['kind'=>'list', 'key'=>$key, 'opened'=>false, 'count'=>0];
    return $this;
  }

  /** Поле объекта */
  public function put(string $key, string|int|bool|null $value): self
  {
    $i = $this->top();
    if ($this->stack[$i]['kind'] !== 'object')
      throw new LogicException('put() допустим только внутри объекта');

    $this->ensureOpen($i);
    if ($this->stack[$i]['count']++ > 0)
      $this->sink->separator();

    $this->sink->scalar($value, $key);
    return $this;
  }

  /** Элемент массива */
  public function putValue(string|int|bool|null $value): self
  {
    $i = $this->top();
    if ($this->stack[$i]['kind'] !== 'list')
      throw new LogicException('putValue() допустим только внутри массива');

    $this->ensureOpen($i);
    if ($this->stack[$i]['count']++ > 0)
      $this->sink->separator();

    $this->sink->scalar($value, null);
    return $this;
  }

  public function close(): self
  {
    if ($this->stack === [])
      throw new LogicException('close() без открытого контейнера');

    $frame = array_pop($this->stack);
    if ($frame['opened'])
      $this->sink->close($frame['kind']);

    return $this;
  }

  public function flush(): void { $this->sink->flush(); }

  private function top(): int
  {
    if ($this->stack === [])
      throw new LogicException('значение вне контейнера');

    return count($this->stack) - 1;
  }

  /**
   * Контейнер пишется не в момент открытия, а в момент первого ребёнка:
   * поэтому пустые секции не попадают в вывод вовсе.
   */
  private function ensureOpen(int $i): void
  {
    if ($this->stack[$i]['opened'])
      return;

    if ($i > 0)
    {
      $this->ensureOpen($i - 1);
      if ($this->stack[$i - 1]['count']++ > 0)
        $this->sink->separator();
    }

    $this->sink->open($this->stack[$i]['kind'], $this->stack[$i]['key']);
    $this->stack[$i]['opened'] = true;
  }

  static function test(TestRunner $t)
  {
    $build = function (JsonWriter $w) {
      $w->openObject();
      $w->put('id'  , 1);
      $w->put('flag', true);
      $w->put('name', 'text');
      $w->openList('items');
      $w->openObject();
      $w->put('a', 'x');
      $w->put('b', 2);
      $w->close();
      $w->close();
      $w->openList('empty');
      $w->close();
      $w->openObject('nested');
      $w->close();
      $w->close();
    };

    // Точная строка: пустые контейнеры не попадают в вывод
    $streamSink = new JsonStreamSink();
    $build(new JsonWriter($streamSink));
    $t->eq($streamSink->data(), '{"id":1,"flag":true,"name":"text","items":[{"a":"x","b":2}]}',
      'JsonWriter: точная строка, пустые контейнеры пропущены');

    // Та же структура массивом
    $arraySink = new JsonArraySink();
    $build(new JsonWriter($arraySink));
    $t->eq($arraySink->data(), ['id'=>1, 'flag'=>true, 'name'=>'text', 'items'=>[['a'=>'x', 'b'=>2]]],
      'JsonWriter: та же структура массивом');
    $t->eq($streamSink->data(),
      json_encode($arraySink->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      'JsonWriter: строка и массив согласованы');

    // Реальный поток: несколько сбросов по порогу
    $fp   = fopen('php://memory', 'w+');
    $sink = new JsonStreamSink($fp, 16);
    $build(new JsonWriter($sink));
    $sink->flush();
    rewind($fp);
    $t->eq(stream_get_contents($fp), $streamSink->data(), 'JsonWriter: приёмник пишет в поток');
    $t->eq($sink->data(), '', 'JsonWriter: после flush буфер пуст');
    fclose($fp);

    // Элементы массива
    $s2 = new JsonStreamSink();
    $w2 = new JsonWriter($s2);
    $w2->openObject();
    $w2->openList('list');
    $w2->putValue(1);
    $w2->putValue('two');
    $w2->close();
    $w2->close();
    $t->eq($s2->data(), '{"list":[1,"two"]}', 'JsonWriter: putValue в массиве');

    // Нарушения протокола
    $t->throws(fn() => (new JsonWriter(new JsonStreamSink()))->put('a', 1), 'JsonWriter: put() вне объекта -> исключение');
    $t->throws(fn() => (new JsonWriter(new JsonStreamSink()))->close(), 'JsonWriter: close() без контейнера -> исключение');

    $w3 = new JsonWriter(new JsonStreamSink());
    $w3->openList();
    $t->throws(fn() => $w3->put('a', 1), 'JsonWriter: put() внутри массива -> исключение');

    // Не-UTF8 строка не превращается в битый JSON
    $t->throws(fn() => JsonStreamSink::encode("\xCF\xF0\xE8"), 'JsonWriter: не-UTF8 строка -> исключение');
  }}
