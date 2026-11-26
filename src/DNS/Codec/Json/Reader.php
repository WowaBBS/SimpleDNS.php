<?
/* ============================================================
 * JsonReader - контролируемое чтение уже разобранного JSON.
 * Зеркало DnsReader: единственное место, где структура
 * проверяется, а ошибки получают путь до поля.
 * ============================================================ */

final class JsonReader
{
  public function __construct(
    private array  $data,
    private string $path = '$',
  ) {}

  public function path(): string { return $this->path; }

  public function has(string $key): bool { return $this->find($key) !== null; }

  public function raw(string $key): mixed
  {
    $found = $this->find($key);
    return $found === null ? null : $this->data[$found];
  }

  /**
   * Поле ищется без учёта регистра: RFC 8427 пишет "Name"/"Type",
   * а Google /resolve отдаёт "name"/"type" в тех же объектах.
   */
  private function find(string $key): ?string
  {
    if (array_key_exists($key, $this->data))
      return $key;

    $lower = strtolower($key);
    foreach ($this->data as $name => $_)
      if (strtolower((string)$name) === $lower)
        return (string)$name;

    return null;
  }

  /* ---------- Обязательные значения ---------- */

  public function str(string $key): string
  {
    $value = $this->required($key);
    if (!is_string($value))
      $this->wrongType($key, 'строка', $value);

    return $value;
  }

  public function int(string $key): int
  {
    $value = $this->required($key);
    if (is_int($value))
      return $value;

    if (is_string($value) && preg_match('/^-?\d+$/', $value))
      return (int)$value;

    $this->wrongType($key, 'целое число', $value);
  }

  /** Строка или целое без приведения: тип записи приходит и как "MX", и как 15 */
  public function strOrInt(string $key): string|int
  {
    $value = $this->required($key);
    if (is_int($value) || is_string($value))
      return $value;

    $this->wrongType($key, 'строка или целое', $value);
  }

  public function bool(string $key): bool
  {
    $value = $this->required($key);
    if (is_bool($value))
      return $value;

    if ($value === 0 || $value === 1)
      return (bool)$value;

    $this->wrongType($key, 'логическое значение', $value);
  }

  /* ---------- Значения со значением по умолчанию ---------- */

  public function strOr (string $key, string $default): string { return $this->has($key) ? $this->str ($key) : $default; }
  public function intOr (string $key, int    $default): int    { return $this->has($key) ? $this->int ($key) : $default; }
  public function boolOr(string $key, bool   $default): bool   { return $this->has($key) ? $this->bool($key) : $default; }

  /* ---------- Вложенные структуры ---------- */

  public function obj(string $key): self
  {
    $value = $this->required($key);
    if (!is_array($value))
      $this->wrongType($key, 'объект', $value);

    return new self($value, $this->path . '.' . $key);
  }

  /** @return self[] */
  public function list(string $key): array
  {
    if (!$this->has($key))
      return [];

    $value = $this->data[$key];
    if (!is_array($value))
      $this->wrongType($key, 'массив', $value);

    $result = [];
    foreach (array_values($value) as $i => $item)
    {
      $path = $this->path . '.' . $key . '[' . $i . ']';
      if (!is_array($item))
        throw new RuntimeException(sprintf('%s: ожидался объект, получено %s', $path, get_debug_type($item)));

      $result[] = new self($item, $path);
    }

    return $result;
  }

  /* ---------- Ошибки ---------- */

  private function required(string $key): mixed
  {
    $found = $this->find($key);

    if ($found === null)
      throw new RuntimeException("{$this->path}: нет поля $key");

    return $this->data[$found];
  }

  private function wrongType(string $key, string $expected, mixed $got): never
  {
    throw new RuntimeException(sprintf('%s.%s: ожидался %s, получено %s',
      $this->path, $key, $expected, get_debug_type($got)));
  }

  /* ------------------------------------------------------------
   * Тесты
   * ------------------------------------------------------------ */

  static function test(TestRunner $t)
  {
    $j = new JsonReader(['a'=>1, 'b'=>'2', 'c'=>'текст', 'd'=>true, 'e'=>0,
      'obj'=>['x'=>5], 'list'=>[['n'=>1],['n'=>2]], 'empty'=>[]]);

    $t->eq($j->int('a'), 1, 'JsonReader: int из числа');
    $t->eq($j->int('b'), 2, 'JsonReader: int из строки цифр');
    $t->eq($j->str('c'), 'текст', 'JsonReader: строка');
    $t->eq($j->bool('d'), true, 'JsonReader: bool из true');
    $t->eq($j->bool('e'), false, 'JsonReader: bool из 0');
    $t->eq($j->intOr('missing', 7), 7, 'JsonReader: intOr по умолчанию');
    $t->eq($j->has('a'), true, 'JsonReader: has');
    $t->eq($j->has('zzz'), false, 'JsonReader: has отсутствующего');

    // Ключи без учёта регистра: так отвечает Google /resolve
    $lower = new JsonReader(['name' => 'example.com.', 'type' => 1]);
    $t->eq($lower->str('Name'), 'example.com.', 'JsonReader: поле в нижнем регистре');
    $t->eq($lower->int('TYPE'), 1, 'JsonReader: int по имени в другом регистре');
    $t->eq($lower->has('NAME'), true, 'JsonReader: has без учёта регистра');
    $t->eq($lower->strOrInt('type'), 1, 'JsonReader: strOrInt из числа');
    $t->eq((new JsonReader(['Type' => 'MX']))->strOrInt('Type'), 'MX', 'JsonReader: strOrInt из строки');

    $o = $j->obj('obj');
    $t->eq($o->int('x'), 5, 'JsonReader: вложенный объект');
    $t->eq($o->path(), '$.obj', 'JsonReader: путь вложенного объекта');

    $list = $j->list('list');
    $t->eq(count($list), 2, 'JsonReader: список объектов');
    $t->eq($list[1]->int('n'), 2, 'JsonReader: элемент списка');
    $t->eq($list[1]->path(), '$.list[1]', 'JsonReader: путь элемента списка');
    $t->eq($j->list('empty'), [], 'JsonReader: пустой список');
    $t->eq($j->list('missing'), [], 'JsonReader: отсутствующий список');

    // Ошибки: тексты должны называть путь и поле
    foreach ([
      ['нет поля',            fn() => $j->str('missing')],
      ['целое число',         fn() => $j->int('c')],
      ['логическое значение', fn() => $j->bool('c')],
      ['ожидался объект',     fn() => $j->obj('a')],
      ['ожидался массив',     fn() => $j->list('a')],
      ['$.obj.x',             fn() => $j->obj('obj')->str('x')],
      ['$.list[0].n',         fn() => $j->list('list')[0]->str('n')],
    ] as [$needle, $call])
    {
      try { $call(); $t->ok(false, "JsonReader: ожидалось исключение ($needle)"); }
      catch (RuntimeException $e) { $t->ok(str_contains($e->getMessage(), $needle), "JsonReader: ошибка содержит '$needle' ({$e->getMessage()})"); }
    }
  }
}
