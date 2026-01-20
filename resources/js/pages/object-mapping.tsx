import { Head, router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import {
    details as objectMappingDetails,
    index as objectMappingIndex,
    refresh as objectMappingRefresh,
} from '@/routes/object-mapping';
import { type BreadcrumbItem } from '@/types';

type MappingField = {
    name?: string | null;
    machine_name?: string | null;
};

type MappingEntry = {
    name: string;
    status: string;
    dev2: {
        table: string | null;
        fields: Array<string | MappingField>;
        field_count: number;
    };
    test: {
        table: string | null;
        fields: Array<string | MappingField>;
        field_count: number;
    };
};

type PageProps = {
    entries: MappingEntry[];
    labels: {
        dev2: string;
        test: string;
    };
    last_refresh: {
        dev2: string | null;
        test: string | null;
    };
    errors: {
        dev2: string[];
        test: string[];
    };
    notice?: string | null;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Cartographie',
        href: objectMappingIndex().url,
    },
];

const statusBadgeVariant = (status: string) => {
    if (status === 'Identique') {
        return 'secondary';
    }
    return 'destructive';
};

export default function ObjectMapping({ entries, labels, errors, last_refresh, notice }: PageProps) {
    const [query, setQuery] = useState('');
    const [detailEntry, setDetailEntry] = useState<MappingEntry | null>(null);
    const [detailLoading, setDetailLoading] = useState(false);
    const detailCache = useRef<Record<string, MappingEntry>>({});
    const [refreshing, setRefreshing] = useState(false);
    const [message, setMessage] = useState<string | null>(notice ?? null);
    useEffect(() => {
        detailCache.current = {};
    }, [entries]);
    useEffect(() => {
        if (!notice) {
            return;
        }
        setMessage(notice);
        const timer = setTimeout(() => setMessage(null), 2500);
        return () => clearTimeout(timer);
    }, [notice]);

    const filteredEntries = useMemo(() => {
        const needle = query.trim().toLowerCase();
        if (!needle) {
            return entries;
        }
        return entries.filter((entry) => {
            const dev2Table = entry.dev2.table ?? '';
            const testTable = entry.test.table ?? '';
            const haystack = `${entry.name} ${dev2Table} ${testTable}`.toLowerCase();
            return haystack.includes(needle);
        });
    }, [entries, query]);

    const [selectedName, setSelectedName] = useState<string | null>(entries[0]?.name ?? null);

    useEffect(() => {
        if (!selectedName && filteredEntries[0]?.name) {
            setSelectedName(filteredEntries[0].name);
            return;
        }
        const stillExists = filteredEntries.some((entry) => entry.name === selectedName);
        if (!stillExists) {
            setSelectedName(filteredEntries[0]?.name ?? null);
        }
    }, [filteredEntries, selectedName]);

    const selectedEntry = useMemo(
        () => entries.find((entry) => entry.name === selectedName) ?? null,
        [entries, selectedName],
    );

    useEffect(() => {
        if (!selectedName) {
            setDetailEntry(null);
            return;
        }
        let isActive = true;
        if (detailCache.current[selectedName]) {
            setDetailEntry(detailCache.current[selectedName]);
            setDetailLoading(false);
            return () => {
                isActive = false;
            };
        }
        setDetailEntry(null);
        setDetailLoading(true);
        fetch(
            objectMappingDetails.url({
                query: { name: selectedName },
            }),
            {
                headers: {
                    Accept: 'application/json',
                },
            },
        )
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error('Request failed');
                }
                return response.json();
            })
            .then((data: MappingEntry) => {
                if (!isActive) {
                    return;
                }
                detailCache.current[selectedName] = data;
                setDetailEntry(data);
            })
            .catch(() => {
                if (!isActive) {
                    return;
                }
                setDetailEntry(null);
            })
            .finally(() => {
                if (!isActive) {
                    return;
                }
                setDetailLoading(false);
            });

        return () => {
            isActive = false;
        };
    }, [entries, selectedName]);

    const activeEntry = detailEntry ?? selectedEntry;

    const fieldRows = useMemo(() => {
        if (!activeEntry) {
            return [];
        }
        const normalizeFields = (fields: Array<string | MappingField>) => {
            const map = new Map<string, { name: string; machine_name: string | null }>();
            fields.forEach((field) => {
                const rawName = typeof field === 'string' ? field : field.name ?? '';
                const rawMachine = typeof field === 'string' ? null : field.machine_name ?? null;
                const name = rawName.trim();
                const machine = rawMachine ? rawMachine.trim() : null;
                const keySource = name !== '' ? name : machine ?? '';
                if (!keySource) {
                    return;
                }
                const key = keySource.toLowerCase();
                const existing = map.get(key);
                if (existing) {
                    if (!existing.machine_name && machine) {
                        existing.machine_name = machine;
                    }
                    return;
                }
                map.set(key, {
                    name: name || machine || keySource,
                    machine_name: machine,
                });
            });
            return map;
        };

        const dev2Fields = normalizeFields(activeEntry.dev2.fields ?? []);
        const testFields = normalizeFields(activeEntry.test.fields ?? []);
        const allKeys = new Set([...dev2Fields.keys(), ...testFields.keys()]);
        const sortedKeys = Array.from(allKeys).sort();

        return sortedKeys.map((key, index) => ({
            index: index + 1,
            key,
            dev2: dev2Fields.get(key) ?? null,
            test: testFields.get(key) ?? null,
        }));
    }, [activeEntry]);

    const identicalCount = useMemo(
        () => entries.filter((entry) => entry.status === 'Identique').length,
        [entries],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cartographie" />
            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Cartographie des objets</CardTitle>
                        <CardDescription>
                            Recherchez un objet ou une table pour afficher les champs DEV2 et TEST.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="text-xs text-muted-foreground">
                                Dernier refresh: {labels.dev2} {last_refresh.dev2 ?? 'n/a'} | {labels.test}{' '}
                                {last_refresh.test ?? 'n/a'}
                            </div>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={refreshing}
                                onClick={() => {
                                    setRefreshing(true);
                                    router.post(objectMappingRefresh.url(), {}, {
                                        preserveScroll: true,
                                        onFinish: () => setRefreshing(false),
                                    });
                                }}
                            >
                                {refreshing ? 'Rafraichissement...' : 'Rafraichir'}
                            </Button>
                        </div>
                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="space-y-2">
                                <Label htmlFor="object-search">Recherche</Label>
                                <Input
                                    id="object-search"
                                    value={query}
                                    onChange={(event) => setQuery(event.target.value)}
                                    placeholder="Objet ou table"
                                />
                            </div>
                            <div className="flex flex-wrap items-end gap-2 text-sm text-muted-foreground">
                                <Badge variant="secondary">Total: {entries.length}</Badge>
                                <Badge variant="secondary">Identiques: {identicalCount}</Badge>
                                <Badge variant="secondary">Differents: {entries.length - identicalCount}</Badge>
                            </div>
                        </div>

                        {(errors.dev2.length > 0 || errors.test.length > 0) && (
                            <div className="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm">
                                {errors.dev2.length > 0 && (
                                    <div>{labels.dev2}: {errors.dev2.join(', ')}</div>
                                )}
                                {errors.test.length > 0 && (
                                    <div>{labels.test}: {errors.test.join(', ')}</div>
                                )}
                            </div>
                        )}
                        {message && <div className="text-xs text-muted-foreground">{message}</div>}
                    </CardContent>
                </Card>

                <div className="grid gap-6 lg:grid-cols-[1.1fr,1.4fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Objets</CardTitle>
                            <CardDescription>Selectionnez un objet pour voir les details.</CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {filteredEntries.length === 0 ? (
                                <div className="rounded-md border bg-muted/30 p-4 text-sm text-muted-foreground">
                                    Aucun resultat.
                                </div>
                            ) : (
                                <div className="overflow-auto rounded-md border">
                                    <table className="min-w-full text-xs">
                                        <thead className="bg-muted/40 text-left">
                                            <tr>
                                                <th className="px-3 py-2 font-medium">Objet</th>
                                                <th className="px-3 py-2 font-medium">{labels.dev2}</th>
                                                <th className="px-3 py-2 font-medium">{labels.test}</th>
                                                <th className="px-3 py-2 font-medium">Statut</th>
                                                <th className="px-3 py-2 font-medium">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {filteredEntries.map((entry) => (
                                                <tr key={entry.name} className="border-t">
                                                    <td className="px-3 py-2 font-medium">{entry.name}</td>
                                                    <td className="px-3 py-2">{entry.dev2.table ?? 'n/a'}</td>
                                                    <td className="px-3 py-2">{entry.test.table ?? 'n/a'}</td>
                                                    <td className="px-3 py-2">
                                                        <Badge variant={statusBadgeVariant(entry.status)}>
                                                            {entry.status}
                                                        </Badge>
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <Button
                                                            size="sm"
                                                            variant="secondary"
                                                            onClick={() => setSelectedName(entry.name)}
                                                        >
                                                            Ouvrir
                                                        </Button>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Details</CardTitle>
                            <CardDescription>
                                {activeEntry ? activeEntry.name : 'Selectionnez un objet.'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {activeEntry ? (
                                <>
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div className="rounded-md border bg-muted/30 p-3 text-sm">
                                            <div className="text-xs text-muted-foreground">{labels.dev2}</div>
                                            <div className="text-sm font-medium">
                                                {activeEntry.dev2.table ?? 'n/a'}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                Champs: {activeEntry.dev2.field_count}
                                            </div>
                                        </div>
                                        <div className="rounded-md border bg-muted/30 p-3 text-sm">
                                            <div className="text-xs text-muted-foreground">{labels.test}</div>
                                            <div className="text-sm font-medium">
                                                {activeEntry.test.table ?? 'n/a'}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                Champs: {activeEntry.test.field_count}
                                            </div>
                                        </div>
                                    </div>

                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div className="overflow-auto rounded-md border">
                                            <table className="min-w-full text-xs">
                                                <thead className="bg-muted/40 text-left">
                                                    <tr>
                                                        <th className="px-3 py-2 font-medium">#</th>
                                                        <th className="px-3 py-2 font-medium">{labels.dev2} nom</th>
                                                        <th className="px-3 py-2 font-medium">Nom machine</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {fieldRows.map((row) => (
                                                        <tr
                                                            key={`dev2-${row.key}`}
                                                            className={`border-t ${row.dev2 ? '' : 'bg-rose-500/10'}`}
                                                        >
                                                            <td className="px-3 py-2 text-muted-foreground">
                                                                {row.index}
                                                            </td>
                                                            <td className="px-3 py-2">
                                                                {row.dev2 ? row.dev2.name : '-'}
                                                            </td>
                                                            <td className="px-3 py-2">
                                                                {row.dev2?.machine_name ?? '-'}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                        <div className="overflow-auto rounded-md border">
                                            <table className="min-w-full text-xs">
                                                <thead className="bg-muted/40 text-left">
                                                    <tr>
                                                        <th className="px-3 py-2 font-medium">#</th>
                                                        <th className="px-3 py-2 font-medium">{labels.test} nom</th>
                                                        <th className="px-3 py-2 font-medium">Nom machine</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {fieldRows.map((row) => (
                                                        <tr
                                                            key={`test-${row.key}`}
                                                            className={`border-t ${row.test ? '' : 'bg-rose-500/10'}`}
                                                        >
                                                            <td className="px-3 py-2 text-muted-foreground">
                                                                {row.index}
                                                            </td>
                                                            <td className="px-3 py-2">
                                                                {row.test ? row.test.name : '-'}
                                                            </td>
                                                            <td className="px-3 py-2">
                                                                {row.test?.machine_name ?? '-'}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    {detailLoading && (
                                        <div className="text-xs text-muted-foreground">
                                            Chargement des champs...
                                        </div>
                                    )}
                                </>
                            ) : (
                                <div className="rounded-md border bg-muted/30 p-4 text-sm text-muted-foreground">
                                    Selectionnez un objet pour voir les champs.
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
