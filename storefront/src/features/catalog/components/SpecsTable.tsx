import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableRow from '@mui/material/TableRow';

export function SpecsTable({ specs }: { specs: { label: string; value: string }[] }) {
  if (!specs.length) return null;
  return (
    <Table size="small" aria-label="Especificações técnicas">
      <TableBody>
        {specs.map((s) => (
          <TableRow key={s.label}>
            <TableCell component="th" scope="row" sx={{ color: 'text.secondary', width: '45%' }}>
              {s.label}
            </TableCell>
            <TableCell>{s.value}</TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}
