# Postgres with pgvector (recipe search by meaning, docs/INGREDIENT-KNOWLEDGE.md), on the same
# alpine image as before: switching to pgvector's Debian image would change the C library, and
# with it how text sorts, under the existing data's indexes.
FROM postgres:17-alpine

ARG PGVECTOR_VERSION=0.8.1

# Built without -march=native (OPTFLAGS) so the image runs on any CPU of its architecture, and
# without LLVM bitcode (with_llvm) so no clang is needed.
RUN apk add --no-cache --virtual .build-deps git build-base \
    && git clone --branch "v${PGVECTOR_VERSION}" --depth 1 https://github.com/pgvector/pgvector.git /tmp/pgvector \
    && make -C /tmp/pgvector OPTFLAGS="" with_llvm=no \
    && make -C /tmp/pgvector install with_llvm=no \
    && rm -rf /tmp/pgvector \
    && apk del .build-deps
