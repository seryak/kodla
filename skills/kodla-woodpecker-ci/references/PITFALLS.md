# Woodpecker CI — Known Pitfalls

Эти ошибки задокументированы в реальной эксплуатации. Применяй исправления в каждом генерируемом pipeline.

---

## Pitfall 1: Git LFS не установлен на агенте

**Симптом:** `git: 'lfs' is not a git command`

**Причина:** Woodpecker-агенты по умолчанию не имеют git-lfs.

**Исправление:** Всегда явно отключай LFS в секции clone:

```yaml
clone:
  git:
    image: woodpeckerci/plugin-git
    settings:
      lfs: false
```

**Применять:** В каждом pipeline файле без исключений.

---

## Pitfall 2: image на local backend интерпретируется как shell-бинарь

**Симптом:** `exec: "alpine": executable file not found in $PATH`

**Причина:** На `WOODPECKER_BACKEND=local` поле `image:` — это имя исполняемого файла
на хосте (shell), а не Docker image.

**Исправление:** Для local backend всегда используй имя shell:

```yaml
steps:
  - name: deploy
    image: bash   # ПРАВИЛЬНО для local backend
    # НЕ: image: alpine:3.20  (это для docker backend)
```

**Матрица:**
| Backend | image поле |
|---------|-----------|
| docker | Docker image (composer:2, alpine:3.20, node:22) |
| local | Shell на хосте (bash, sh, zsh) |
| kubernetes | Pod image |

---

## Pitfall 3: labels работают только на уровне workflow

**Симптом:** Шаг выполняется не тем агентом, labels внутри steps игнорируются молча.

**Причина:** `labels:` маршрутизирует весь workflow целиком — нельзя назначить разные
backend для разных steps в одном файле.

**Исправление:** labels только на верхнем уровне файла:

```yaml
labels:         # ПРАВИЛЬНО — уровень workflow
  backend: docker

steps:
  - name: build
    image: composer:2
    # НЕ добавляй labels здесь — они игнорируются
```

**Следствие:** Для multi-backend нужны два отдельных yml-файла.

---

## Pitfall 4: Tar "file changed as we read it"

**Симптом:** `tar: .: file changed as we read it` при создании архива текущей директории.

**Причина:** Создание tar-файла в директории, которую архивируешь, изменяет её mtime
в процессе работы.

**Исправление:** Писать в `/tmp`, потом перемещать:

```yaml
commands:
  - tar --exclude='.git' --exclude='node_modules' -czf /tmp/app.tar.gz .
  - mv /tmp/app.tar.gz app.tar.gz
```

**Или** для передачи через volume:

```yaml
commands:
  - tar --exclude='.git' -czf /tmp/app.tar.gz .
  - mv /tmp/app.tar.gz /out/app.tar.gz
```

---

## Pitfall 5: Volume mount — "Insufficient trust level"

**Симптом:** `Insufficient trust level to use volumes`

**Причины и исправления (оба нужны):**

1. Репозиторий не отмечен как Trusted в UI:
   - Settings репозитория → Trusted → включить чекбокс

2. Docker-агент не настроен с разрешёнными путями:
   ```env
   # /etc/woodpecker/woodpecker-agent-docker.env
   WOODPECKER_BACKEND_DOCKER_VOLUMES=/tmp/woodpecker-build:/tmp/woodpecker-build
   ```

**Применять:** Всегда документируй volume-requirements в комментарии рядом с volumes.

---

## Pitfall 6: config_path в настройках репозитория сломан

**Симптом:** `pipeline definition not found` или `.woodpecker is a folder not a file`

**Причина:** Поле `config_path` в настройках репозитория Woodpecker форсирует загрузку
одного конкретного файла — если указана папка `.woodpecker`, это не работает.

**Исправление:** Оставить `config_path` пустым в UI настройках репозитория.

Woodpecker ищет в таком порядке:
1. `.woodpecker/*.yml` и `.woodpecker/*.yaml` (все файлы)
2. `.woodpecker.yml`
3. `.woodpecker.yaml`

**Применять:** Упоминать в инструкциях по настройке репозитория.

---

## Быстрая шпаргалка

| Ошибка | Причина | Фикс |
|--------|---------|------|
| `git: 'lfs' is not a git command` | LFS не отключен | `settings.lfs: false` |
| `exec: "alpine": not found` | image на local backend = shell | `image: bash` |
| labels игнорируются | labels внутри steps | labels на уровне workflow |
| `tar: file changed` | tar пишет в ту же папку | писать в /tmp, потом mv |
| `Insufficient trust level` | volumes без Trust + env | Trust в UI + env агента |
| `pipeline definition not found` | config_path в настройках | очистить config_path |
