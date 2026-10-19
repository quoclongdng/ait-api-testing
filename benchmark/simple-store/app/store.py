"""In-memory data store for the simple-store benchmark API."""

import threading
from datetime import UTC, datetime
from typing import Any


class DataStore:
    """Thread-safe in-memory store for simple-store resources."""

    def __init__(self) -> None:
        self._lock = threading.Lock()
        self.categories: dict[int, dict[str, Any]] = {}
        self.products: dict[int, dict[str, Any]] = {}
        self._category_seq: int = 0
        self._product_seq: int = 0
        self.seed_defaults()

    def seed_defaults(self) -> None:
        """Seed initial dataset for exploration and testing."""
        with self._lock:
            self.categories.clear()
            self.products.clear()
            self._category_seq = 0
            self._product_seq = 0

            # Seed initial categories
            cat1 = self._add_category_unlocked(
                name="Books",
                slug="books",
                description="Books and publications",
            )
            cat2 = self._add_category_unlocked(
                name="Electronics",
                slug="electronics",
                description="Gadgets, computers, and accessories",
            )

            # Seed initial products
            now = datetime.now(UTC).isoformat()
            self._add_product_unlocked(
                name="Software Testing Guide",
                sku="BOOK-001",
                price=39.99,
                category_id=cat1["id"],
                quantity=150,
                description="Complete guide to automated API testing",
                created_at=now,
            )
            self._add_product_unlocked(
                name="Wireless Mechanical Keyboard",
                sku="TECH-001",
                price=89.50,
                category_id=cat2["id"],
                quantity=45,
                description="Compact RGB mechanical keyboard",
                created_at=now,
            )

    def reset(self) -> None:
        """Reset state back to initial defaults."""
        self.seed_defaults()

    def clear_all(self) -> None:
        """Clear all records completely (empty database)."""
        with self._lock:
            self.categories.clear()
            self.products.clear()
            self._category_seq = 0
            self._product_seq = 0

    # ---------------- Category Operations ----------------

    def _add_category_unlocked(
        self, name: str, slug: str, description: str | None = None
    ) -> dict[str, Any]:
        self._category_seq += 1
        cat_id = self._category_seq
        cat = {
            "id": cat_id,
            "name": name,
            "slug": slug,
            "description": description,
        }
        self.categories[cat_id] = cat
        return cat

    def get_category(self, category_id: int) -> dict[str, Any] | None:
        with self._lock:
            return self.categories.get(category_id)

    def get_category_by_slug(self, slug: str) -> dict[str, Any] | None:
        with self._lock:
            for cat in self.categories.values():
                if cat["slug"] == slug:
                    return cat
            return None

    def list_categories(self, limit: int = 50, offset: int = 0) -> list[dict[str, Any]]:
        with self._lock:
            items = list(self.categories.values())
            return items[offset : offset + limit]

    def create_category(
        self, name: str, slug: str, description: str | None = None
    ) -> dict[str, Any]:
        with self._lock:
            return self._add_category_unlocked(name, slug, description)

    # ---------------- Product Operations ----------------

    def _add_product_unlocked(
        self,
        name: str,
        sku: str,
        price: float,
        category_id: int,
        quantity: int = 0,
        description: str | None = None,
        created_at: str | None = None,
    ) -> dict[str, Any]:
        self._product_seq += 1
        prod_id = self._product_seq
        prod = {
            "id": prod_id,
            "name": name,
            "sku": sku,
            "price": float(price),
            "category_id": int(category_id),
            "quantity": int(quantity),
            "description": description,
            "created_at": created_at or datetime.now(UTC).isoformat(),
        }
        self.products[prod_id] = prod
        return prod

    def get_product(self, product_id: int) -> dict[str, Any] | None:
        with self._lock:
            return self.products.get(product_id)

    def get_product_by_sku(self, sku: str) -> dict[str, Any] | None:
        with self._lock:
            for prod in self.products.values():
                if prod["sku"] == sku:
                    return prod
            return None

    def list_products(
        self, limit: int = 20, offset: int = 0, category_id: int | None = None
    ) -> list[dict[str, Any]]:
        with self._lock:
            items = list(self.products.values())
            if category_id is not None:
                items = [p for p in items if p["category_id"] == category_id]
            return items[offset : offset + limit]

    def create_product(
        self,
        name: str,
        sku: str,
        price: float,
        category_id: int,
        quantity: int = 0,
        description: str | None = None,
    ) -> dict[str, Any]:
        with self._lock:
            return self._add_product_unlocked(
                name=name,
                sku=sku,
                price=price,
                category_id=category_id,
                quantity=quantity,
                description=description,
            )

    def update_product(self, product_id: int, updates: dict[str, Any]) -> dict[str, Any] | None:
        with self._lock:
            prod = self.products.get(product_id)
            if prod is None:
                return None
            for key, val in updates.items():
                if val is not None and key in prod and key not in ("id", "created_at"):
                    prod[key] = val
            return dict(prod)

    def delete_product(self, product_id: int) -> bool:
        with self._lock:
            if product_id in self.products:
                del self.products[product_id]
                return True
            return False


# Global singleton store
store = DataStore()
