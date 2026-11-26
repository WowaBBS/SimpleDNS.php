<?
/* ============================================================
 * JsonArraySink - собирает вложенные массивы (для toJson()).
 * Запятые не нужны: структуру задаёт сам массив.
 * ============================================================ */

final class JsonArraySink implements JsonSink
{
  /** @var array<int,array{key:?string,data:array}> */
  private array $stack = [];
  private array $root  = [];

  public function open(string $kind, ?string $key): void
  {
    $this->stack[] = ['key'=>$key, 'data'=>[]];
  }

  public function close(string $kind): void
  {
    if ($this->stack === [])
      throw new LogicException('close() без открытого контейнера');

    $frame = array_pop($this->stack);
    if ($this->stack === [])
    {
      $this->root = $frame['data'];
      return;
    }

    $i = count($this->stack) - 1;
    if ($frame['key'] === null)
      $this->stack[$i]['data'][] = $frame['data'];
    else
      $this->stack[$i]['data'][$frame['key']] = $frame['data'];
  }

  public function separator(): void
  {
    // массив не нуждается в запятых - разделители появляются при сборке строки
  }

  public function scalar(string|int|bool|null $value, ?string $key): void
  {
    if ($this->stack === [])
      throw new LogicException('значение вне контейнера');

    $i = count($this->stack) - 1;
    if ($key === null)
      $this->stack[$i]['data'][] = $value;
    else
      $this->stack[$i]['data'][$key] = $value;
  }

  public function flush(): void {}

  public function data(): array { return $this->root; }
}
